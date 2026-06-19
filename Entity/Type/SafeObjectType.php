<?php

namespace JMS\JobQueueBundle\Entity\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

use function is_resource;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function stream_get_contents;
use function unserialize;

/**
 * Stores serialized PHP objects in a BLOB column. Reads the column back as a
 * (silently-tolerant) serialized blob — failures are surfaced as
 * ConversionException rather than PHP warnings.
 *
 * Previously extended Doctrine\DBAL\Types\ObjectType (deprecated in DBAL 3 in
 * favour of JsonType); reimplemented directly on top of Type so the data model
 * stays BLOB+serialize (JsonType would change column type + serialization
 * format, breaking existing data).
 */
class SafeObjectType extends Type
{
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform): string
    {
        return $platform->getBlobTypeDeclarationSQL($fieldDeclaration);
    }

    #[\Override]
    public function convertToDatabaseValue($value, AbstractPlatform $platform): string
    {
        return serialize($value);
    }

    #[\Override]
    public function convertToPHPValue($value, AbstractPlatform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        $value = is_resource($value) ? stream_get_contents($value) : $value;

        // Inline the type name (instead of calling $this->getName()) because
        // Type::getName() is deprecated in DBAL 4 in favour of TypeRegistry::lookupName().
        $typeName = 'jms_job_safe_object';
        set_error_handler(function (int $code, string $message) use ($typeName): bool {
            throw ConversionException::conversionFailedUnserialization($typeName, $message);
        });

        try {
            return unserialize($value);
        } finally {
            restore_error_handler();
        }
    }

    public function getName(): string
    {
        return 'jms_job_safe_object';
    }
}
