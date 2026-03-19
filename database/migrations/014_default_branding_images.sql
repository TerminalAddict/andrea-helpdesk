-- Set default logo and favicon to bundled images if currently empty
UPDATE settings SET value = '/Andrea-Helpdesk.png'         WHERE key_name = 'logo_url'    AND (value = '' OR value IS NULL);
UPDATE settings SET value = '/Andrea-Helpdesk-favicon.png' WHERE key_name = 'favicon_url' AND (value = '' OR value IS NULL);
