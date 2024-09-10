-- URL Shortener Database Schema

-- Table for storing shortened URLs
CREATE TABLE shortened_urls (
    id SERIAL PRIMARY KEY,
    original_url TEXT NOT NULL,
    short_code VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
    custom_short_code VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expiration_date TIMESTAMP,
    click_count INT DEFAULT 0,
    qr_code_path VARCHAR(255)
);

-- Index for faster lookups on short_code
CREATE INDEX idx_short_code ON shortened_urls(short_code);

-- Table for storing lists of many links
CREATE TABLE link_lists (
    id SERIAL PRIMARY KEY,
    list_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    short_code VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expiration_date TIMESTAMP,
    qr_code_path VARCHAR(255)
);

-- Table for storing individual links within a list
CREATE TABLE list_items (
    id SERIAL PRIMARY KEY,
    list_id INT REFERENCES link_lists(id) ON DELETE CASCADE,
    url TEXT NOT NULL,
    title VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
);

-- Table for storing online clipboard entries
CREATE TABLE clipboard_entries (
    id SERIAL PRIMARY KEY,
    content TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    short_code VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expiration_date TIMESTAMP,
    qr_code_path VARCHAR(255)
);

-- Function to generate a unique short code
CREATE OR REPLACE FUNCTION generate_unique_short_code() RETURNS VARCHAR(10) AS $$
DECLARE
    chars VARCHAR(50) := 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789üÜäÄöÖß';
    result VARCHAR(10) := '';
    i INT;
BEGIN
    FOR i IN 1..6 LOOP
        result := result || substr(chars, floor(random() * length(chars) + 1)::int, 1);
    END LOOP;
    RETURN result;
END;
$$ LANGUAGE plpgsql;

-- Trigger to automatically generate a short code if not provided
CREATE OR REPLACE FUNCTION auto_generate_short_code()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.short_code IS NULL THEN
        LOOP
            NEW.short_code := generate_unique_short_code();
            EXIT WHEN NOT EXISTS (SELECT 1 FROM shortened_urls WHERE short_code = NEW.short_code);
        END LOOP;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_auto_generate_short_code
BEFORE INSERT ON shortened_urls
FOR EACH ROW
EXECUTE FUNCTION auto_generate_short_code();

-- Trigger for link_lists
CREATE TRIGGER trigger_auto_generate_list_short_code
BEFORE INSERT ON link_lists
FOR EACH ROW
EXECUTE FUNCTION auto_generate_short_code();

-- Trigger for clipboard_entries
CREATE TRIGGER trigger_auto_generate_clipboard_short_code
BEFORE INSERT ON clipboard_entries
FOR EACH ROW
EXECUTE FUNCTION auto_generate_short_code();
