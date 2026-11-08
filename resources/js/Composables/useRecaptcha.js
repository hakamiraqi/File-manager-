/**
 * The reCAPTCHA widget renders as a hidden textarea inside the form, but
 * Inertia submits via fetch/XHR rather than a native form POST, so that
 * value never rides along automatically — it has to be read out and added
 * to the payload by hand before the request goes out.
 */
export function readRecaptchaToken() {
    if (typeof window !== 'undefined' && window.grecaptcha) {
        try {
            return window.grecaptcha.getResponse();
        } catch {
            return '';
        }
    }

    return '';
}
