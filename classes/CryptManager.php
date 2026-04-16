<?php


/**
 * Class CryptManager
 *
 * Handles symmetric encryption and decryption using PHP's openssl extension.
 * It uses AES-256-CBC for encryption and ensures URL-safe base64 encoding for the output.
 *
 * IMPORTANT: This class provides confidentiality but NOT authenticity.
 * For applications requiring strong guarantees against data tampering,
 * consider using authenticated encryption modes like AES-256-GCM,
 * or combine this encryption with a Message Authentication Code (MAC) or HMAC.
 */
class CryptManager
{
    /**
     * @var string The derived encryption key (32 bytes for AES-256).
     */
    private $key;

    /**
     * @var string The cipher method to use (e.g., 'AES-256-CBC').
     */
    private $cipher;

    /**
     * CryptManager constructor.
     *
     * Initializes the CryptManager with a secret key and an optional cipher method.
     * The provided key is hashed using SHA-256 to ensure it's 32 bytes long,
     * which is required for AES-256.
     *
     * @param string $key The secret key used for encryption/decryption.
     * @param string $cipher The encryption cipher method. Defaults to 'AES-256-CBC'.
     */
    public function __construct(string $key, string $cipher = 'AES-256-CBC')
    {
        // Hash the provided key using SHA-256 and get the raw binary output.
        // This ensures the key is exactly 32 bytes (256 bits) for AES-256.
        $this->key = hash('sha256', $key, true); // 32 bytes key

        // Set the encryption cipher.
        $this->cipher = $cipher;
    }

    /**
     * Encrypts the given plaintext data.
     *
     * Generates a random Initialization Vector (IV), encrypts the plaintext,
     * prepends the IV to the ciphertext, base64 encodes the result,
     * and then makes it URL-safe.
     *
     * @param string $plaintext The data to be encrypted.
     * @return string The URL-safe base64 encoded encrypted token, or false on failure.
     */
    public function encrypt(string $plaintext): string|false
    {
        // Get the required IV length for the chosen cipher.
        $iv_length = openssl_cipher_iv_length($this->cipher);

        // Generate a cryptographically secure random IV.
        // A unique IV is crucial for security in CBC mode.
        $iv = random_bytes($iv_length);

        // Encrypt the plaintext using openssl_encrypt.
        // OPENSSL_RAW_DATA ensures raw binary output, which is needed to prepend the IV.
        $ciphertext = openssl_encrypt($plaintext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);

        // If encryption fails, return false.
        if ($ciphertext === false) {
            return false;
        }

        // Concatenate the IV and ciphertext, then base64 encode the combined binary string.
        $encrypted = base64_encode($iv . $ciphertext);

        // Make the base64 string URL-safe by replacing '+' with '-' and '/' with '_',
        // and removing trailing '=' padding characters.
        return rtrim(strtr($encrypted, '+/', '-_'), '=');
    }

    /**
     * Decrypts the given encrypted token.
     *
     * Converts the URL-safe base64 token back to standard base64, decodes it,
     * separates the IV from the ciphertext, and then decrypts the data.
     *
     * @param string $token The URL-safe base64 encoded encrypted token.
     * @return string|false The original plaintext on success, or false on failure (e.g., invalid token, decryption error).
     */
    public function decrypt(string $token): string|false
    {
        // Convert URL-safe characters back to standard base64 characters.
        $token = strtr($token, '-_', '+/');

        // Re-add padding '=' characters if they were removed, necessary for base64_decode.
        $padding = strlen($token) % 4;
        if ($padding > 0) {
            $token .= str_repeat('=', 4 - $padding);
        }

        // Base64 decode the token. The 'true' argument makes it strict, returning false for invalid base64.
        $decoded = base64_decode($token, true);

        // Check if base64 decoding failed.
        if ($decoded === false) {
            return false; // Invalid base64 token
        }

        // Get the required IV length for the chosen cipher.
        $iv_length = openssl_cipher_iv_length($this->cipher);

        // Check if the decoded string is shorter than the IV length, indicating corrupted data.
        if (strlen($decoded) < $iv_length) {
            return false; // Corrupted or invalid data (IV missing or incomplete)
        }

        // Extract the IV from the beginning of the decoded string.
        $iv = substr($decoded, 0, $iv_length);

        // Extract the actual ciphertext, which is everything after the IV.
        $ciphertext = substr($decoded, $iv_length);

        // Decrypt the ciphertext using openssl_decrypt.
        // OPENSSL_RAW_DATA specifies that the input ciphertext is raw binary data.
        $plaintext = openssl_decrypt($ciphertext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);

        // Return the plaintext (or false if decryption failed).
        return $plaintext;
    }
}

// Usage Example

// require_once __DIR__ . '/../config/config.php';

// $crypt = new CryptManager(ENCRYPTION_KEY, ENCRYPTION_METHOD);

// $token = $crypt->encrypt("birth_certificate");
// echo "URL-safe Token: " . $token . "<br>";

// $original = $crypt->decrypt($token);
// echo "Original: " . $original;
