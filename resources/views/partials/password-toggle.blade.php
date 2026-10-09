<style>
    .password-toggle-wrapper {
        position: relative;
    }

    .password-toggle-wrapper.has-password > input {
        padding-right: 3rem;
    }

    .password-toggle-wrapper.has-password > input.is-invalid {
        padding-right: 4.5rem;
        background-position: right 2.75rem center;
    }

    .password-toggle-wrapper + .invalid-feedback {
        display: block;
    }

    .password-toggle-button {
        position: absolute;
        top: 50%;
        right: .25rem;
        z-index: 3;
        display: grid;
        width: 2.25rem;
        height: 2.25rem;
        padding: 0;
        transform: translateY(-50%);
        place-items: center;
        border: 0;
        color: #6c757d;
    }

    .password-toggle-button:hover {
        color: #212529;
    }

    .password-toggle-button[hidden] {
        display: none;
    }

    @media (max-width: 575.98px) {
        .password-toggle-button {
            right: .125rem;
            width: 2.75rem;
            height: 2.75rem;
        }

        .password-toggle-wrapper.has-password > input {
            padding-right: 3.25rem;
        }

        .password-toggle-wrapper.has-password > input.is-invalid {
            padding-right: 4.5rem;
        }
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('input[type="password"]').forEach((input) => {
            if (input.dataset.passwordToggleInitialized) return;

            const wrapper = document.createElement('div');
            const button = document.createElement('button');
            const icon = document.createElement('i');

            wrapper.className = 'password-toggle-wrapper';
            input.after(wrapper);
            wrapper.appendChild(input);

            button.type = 'button';
            button.className = 'btn btn-link password-toggle-button';
            button.setAttribute('aria-label', 'Hiện mật khẩu');
            button.setAttribute('aria-pressed', 'false');
            button.title = 'Hiện mật khẩu';
            button.hidden = !input.value;
            icon.className = 'fa-solid fa-eye';
            icon.setAttribute('aria-hidden', 'true');
            button.appendChild(icon);
            wrapper.appendChild(button);

            const syncVisibility = () => {
                const hasPassword = input.value.length > 0;

                wrapper.classList.toggle('has-password', hasPassword);
                button.hidden = !hasPassword;

                if (!hasPassword && input.type === 'text') {
                    input.type = 'password';
                    button.setAttribute('aria-label', 'Hiện mật khẩu');
                    button.setAttribute('aria-pressed', 'false');
                    button.title = 'Hiện mật khẩu';
                    icon.className = 'fa-solid fa-eye';
                }
            };

            syncVisibility();
            input.addEventListener('input', syncVisibility);
            input.addEventListener('change', syncVisibility);

            button.addEventListener('click', () => {
                const isVisible = input.type === 'password';
                const label = isVisible ? 'Ẩn mật khẩu' : 'Hiện mật khẩu';

                input.type = isVisible ? 'text' : 'password';
                button.setAttribute('aria-label', label);
                button.setAttribute('aria-pressed', String(isVisible));
                button.title = label;
                icon.className = `fa-solid ${isVisible ? 'fa-eye-slash' : 'fa-eye'}`;
            });

            input.dataset.passwordToggleInitialized = 'true';
        });
    });
</script>
