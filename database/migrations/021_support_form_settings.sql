-- Migration 021: Support form anti-spam settings
INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('support_form_recaptcha_site_key', '', 'string', 'support-form', 'reCAPTCHA v3 Site Key'),
('support_form_recaptcha_secret_key', '', 'string', 'support-form', 'reCAPTCHA v3 Secret Key')
ON DUPLICATE KEY UPDATE key_name = key_name;
