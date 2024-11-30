<?php

namespace App\Serializer\Normalizer;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\ContentType;
use App\Metadata\Resource\Factory\UriTemplateFactory;
use Rize\UriTemplate;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Adds API link to contentNodes for ContentType based on the defined 'entityClass'.
 */
class ContentTypeNormalizer implements NormalizerInterface, SerializerAwareInterface {
    public function __construct(
        private NormalizerInterface $decorated,
        private UriTemplate $uriTemplate,
        private UriTemplateFactory $uriTemplateFactory,
        private IriConverterInterface $iriConverter,
    ) {}

    public function supportsNormalization($data, $format = null, array $context = []): bool {
        return $this->decorated->supportsNormalization($data, $format, $context);
    }

    public function normalize($data, $format = null, array $context = []): null|array|\ArrayObject|bool|float|int|string {
        $normalized_data = $this->decorated->normalize($data, $format, $context);

        if ($data instanceof ContentType && isset($data->entityClass)) {
            // get uri for the respective ContentNode entity and add ContentType as query parameter
            [$uriTemplate, $templated] = $this->uriTemplateFactory->createFromResourceClass($data->entityClass);
            $uri = $this->uriTemplate->expand($uriTemplate, ['contentType' => $this->iriConverter->getIriFromResource($data)]);

            // add uri as HAL link
            $normalized_data['_links']['contentNodes']['href'] = $uri;

            // unset the property itself (property definition was only needed to ensure proper API documentation)
            unset($normalized_data['contentNodes']);
        }

        return $normalized_data;
    }

    public function getSupportedTypes(?string $format): array {
        if (method_exists($this->decorated, 'getSupportedTypes')) {
            return $this->decorated->getSupportedTypes($format);
        }

        return ['*' => false];
    }

    public function setSerializer(SerializerInterface $serializer): void {
        if ($this->decorated instanceof SerializerAwareInterface) {
            $this->decorated->setSerializer($serializer);
        }
    }
}
