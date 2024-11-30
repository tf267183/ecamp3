<?php

namespace App\Serializer\Normalizer;

use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * This class modifies the API platform HAL CollectionNormalizer, in order to serialize the contained entries
 * under the relation name `items` instead of `item`.
 */
class CollectionItemsNormalizer implements NormalizerInterface, NormalizerAwareInterface {
    private NormalizerInterface $decorated;

    public function __construct(NormalizerInterface $decorated) {
        $this->decorated = $decorated;
    }

    public function supportsNormalization($data, $format = null, array $context = []): bool {
        return $this->decorated->supportsNormalization($data, $format, $context);
    }

    public function normalize($data, $format = null, array $context = []): null|array|\ArrayObject|bool|float|int|string {
        $normalized_data = $this->decorated->normalize($data, $format, $context);

        if (isset($normalized_data['_embedded'], $normalized_data['_embedded']['item'])) {
            $normalized_data['_embedded']['items'] = $normalized_data['_embedded']['item'];
            unset($normalized_data['_embedded']['item']);

            $normalized_data['_links']['items'] = $normalized_data['_links']['item'];
            unset($normalized_data['_links']['item']);
        } elseif (isset($normalized_data['totalItems']) && 0 === $normalized_data['totalItems']) {
            $normalized_data['_embedded']['items'] = [];
        }

        return $normalized_data;
    }

    public function getSupportedTypes(?string $format): array {
        if (method_exists($this->decorated, 'getSupportedTypes')) {
            return $this->decorated->getSupportedTypes($format);
        }

        return ['*' => false];
    }

    public function setNormalizer(NormalizerInterface $normalizer): void {
        if ($this->decorated instanceof NormalizerAwareInterface) {
            $this->decorated->setNormalizer($normalizer);
        }
    }
}
