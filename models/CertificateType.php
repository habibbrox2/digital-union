<?php

/**
 * Class CertificateType
 *
 * Holds multilingual certificate type values and provides flexible access.
 */
class CertificateType
{
    /**
     * @var string
     */
    public $bn;

    /**
     * @var string
     */
    public $en;

    /**
     * @var string
     */
    public $bl;

    /**
     * CertificateType constructor.
     *
     * @param string $bn Bengali name
     * @param string $en English name
     * @param string $bl Bilingual (slug-style or optional label)
     */
    public function __construct(string $bn, string $en, string $bl)
    {
        $this->bn = $bn;
        $this->en = $en;
        $this->bl = $bl;
    }

    /**
     * Magic getter for backward compatibility.
     * Allows access to `certificate_type` as alias of `bn`.
     *
     * @param string $name
     * @return string|null
     */
    public function __get(string $name): ?string
    {
        if ($name === 'certificate_type') {
            return $this->bn; // default display
        }

        return $this->$name ?? null;
    }

    /**
     * Create a CertificateType object from a database row.
     *
     * @param array $row
     * @return self
     */
    public static function fromRow(array $row): self
    {
        return new self(
            $row['certificate_type_bn'] ?? '',
            $row['certificate_type_en'] ?? '',
            $row['certificate_type_bl'] ?? ''
        );
    }

    /**
     * Maps a row to an array with all certificate type fields including a default alias.
     *
     * @param array $row
     * @return array
     */
    public static function mapCertificateTypeFields(array $row): array
    {
        return [
            'certificate_type'     => $row['certificate_type_bn'] ?? '',
            'certificate_type_bn'  => $row['certificate_type_bn'] ?? '',
            'certificate_type_en'  => $row['certificate_type_en'] ?? '',
            'certificate_type_bl'  => $row['certificate_type_bl'] ?? '',
            'type_url'             => $row['type_url'] ?? '',
        ];
    }

    /**
     * Convert the object to array (optional helper).
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'certificate_type'     => $this->bn,
            'certificate_type_bn'  => $this->bn,
            'certificate_type_en'  => $this->en,
            'certificate_type_bl'  => $this->bl,
        ];
    }
}

