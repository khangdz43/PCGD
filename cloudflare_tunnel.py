from pathlib import Path
import re
import subprocess
import sys


def main():
    project_dir = Path(__file__).resolve().parent
    cloudflared = project_dir / "cloudflared.exe"

    if not cloudflared.is_file():
        print(f"cloudflared.exe was not found: {cloudflared}", file=sys.stderr)
        return 1

    print("Starting Cloudflare Tunnel...")

    try:
        process = subprocess.Popen(
            [str(cloudflared), "tunnel", "--url", "http://localhost:8000"],
            cwd=project_dir,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            encoding="utf-8",
            errors="replace",
            bufsize=1,
        )
    except OSError as error:
        print(f"Could not start cloudflared.exe: {error}", file=sys.stderr)
        return 1

    tunnel_url_found = False

    try:
        if process.stdout is not None:
            # Pattern bắt chuỗi URL dạng https://xxx.trycloudflare.com
            url_pattern = re.compile(r"https://[-a-zA-Z0-9.]+\.trycloudflare\.com")

            for line in process.stdout:
                match = url_pattern.search(line)
                if match and not tunnel_url_found:
                    public_url = match.group(0)
                    print("\n" + "=" * 50)
                    print(f"PUBLIC URL: {public_url}")
                    print("=" * 50 + "\n")
                    print("Tunnel is running. Press Ctrl+C to stop.")
                    tunnel_url_found = True
                    
                # Nếu muốn xem lại log khi cần debug thì bỏ comment dòng dưới:
                # print(line, end="", flush=True)

    except KeyboardInterrupt:
        print("\nStopping Cloudflare Tunnel...")
    finally:
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()

    return process.returncode or 0


if __name__ == "__main__":
    raise SystemExit(main())