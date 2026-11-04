from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
from urllib.error import URLError
from urllib.request import urlopen


BACKEND_URL = "http://127.0.0.1:8000"
FRONTEND_URL = "http://127.0.0.1:4173"
STARTUP_TIMEOUT = 60


def is_available(url: str) -> bool:
    try:
        with urlopen(url, timeout=2):
            return True
    except (OSError, URLError):
        return False


def wait_until_available(
    url: str, process: subprocess.Popen[bytes], service_name: str
) -> bool:
    deadline = time.monotonic() + STARTUP_TIMEOUT
    while time.monotonic() < deadline:
        if process.poll() is not None:
            print(f"{service_name} stopped unexpectedly.", file=sys.stderr)
            return False
        if is_available(url):
            return True
        time.sleep(1)

    print(f"{service_name} did not start at {url}.", file=sys.stderr)
    return False


def stop_process(process: subprocess.Popen[bytes]) -> None:
    if process.poll() is not None:
        return
    process.terminate()
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait()


def main() -> int:
    project_dir = Path(__file__).resolve().parent
    frontend_dir = project_dir / "frontend"
    cloudflared = project_dir / "cloudflared.exe"
    php = shutil.which("php")
    node = shutil.which("node")
    vite = frontend_dir / "node_modules" / "vite" / "bin" / "vite.js"
    processes: list[subprocess.Popen[bytes]] = []

    if not cloudflared.is_file():
        print(f"cloudflared.exe was not found: {cloudflared}", file=sys.stderr)
        return 1
    if php is None:
        print("PHP was not found. Add XAMPP's php folder to PATH.", file=sys.stderr)
        return 1
    if node is None or not vite.is_file():
        print(
            "Frontend dependencies are missing. Run npm --prefix frontend install first.",
            file=sys.stderr,
        )
        return 1

    try:
        print("Building frontend...")
        build = subprocess.run(
            "npm run build",
            cwd=frontend_dir,
            check=False,
            shell=True,
        )
        if build.returncode != 0:
            print("Frontend build failed; Cloudflare Tunnel was not started.", file=sys.stderr)
            return build.returncode

        if is_available(f"{BACKEND_URL}/up"):
            print("Using the Laravel backend already running on port 8000.")
        else:
            print("Starting Laravel backend...")
            backend = subprocess.Popen(
                [php, "artisan", "serve", "--host=127.0.0.1", "--port=8000"],
                cwd=project_dir,
            )
            processes.append(backend)
            if not wait_until_available(
                f"{BACKEND_URL}/up", backend, "Laravel backend"
            ):
                print(
                    "Check that XAMPP MySQL is running and the project's .env is configured.",
                    file=sys.stderr,
                )
                return 1

        if is_available(FRONTEND_URL):
            print("Port 4173 is already serving a frontend; using it.")
        else:
            print("Starting frontend preview...")
            preview = subprocess.Popen(
                [
                    node,
                    str(vite),
                    "preview",
                    "--host=127.0.0.1",
                    "--port=4173",
                    "--strictPort",
                ],
                cwd=frontend_dir,
            )
            processes.append(preview)
            if not wait_until_available(FRONTEND_URL, preview, "Frontend preview"):
                return 1

        print("Starting Cloudflare Quick Tunnel...")
        tunnel = subprocess.Popen(
            [
                str(cloudflared),
                "tunnel",
                "--url",
                FRONTEND_URL,
                "--http-host-header",
                "127.0.0.1:4173",
            ],
            cwd=project_dir,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            encoding="utf-8",
            errors="replace",
            bufsize=1,
        )
        processes.append(tunnel)

        if tunnel.stdout is not None:
            url_pattern = re.compile(r"https://[-a-zA-Z0-9.]+\.trycloudflare\.com")
            for line in tunnel.stdout:
                match = url_pattern.search(line)
                if match:
                    print("\n" + "=" * 50)
                    print(f"PUBLIC URL: {match.group(0)}")
                    print("=" * 50)
                    print("Share this URL. Press Ctrl+C to stop the services.")
                    break
                if tunnel.poll() is not None:
                    print("Cloudflare Tunnel stopped before creating a URL.", file=sys.stderr)
                    return tunnel.returncode or 1

            for line in tunnel.stdout:
                print(line, end="", flush=True)

        return tunnel.wait()
    except KeyboardInterrupt:
        print("\nStopping services...")
        return 0
    except OSError as error:
        print(f"Could not start a required service: {error}", file=sys.stderr)
        return 1
    finally:
        for process in reversed(processes):
            stop_process(process)


if __name__ == "__main__":
    raise SystemExit(main())
