<?php

namespace App\Tests\Api\Activities;

use ApiPlatform\Core\Api\OperationType;
use App\Entity\Activity;
use App\Tests\Api\ECampApiTestCase;

/**
 * @internal
 */
class CreateActivityTest extends ECampApiTestCase {
    // TODO security tests when not logged in or not collaborator
    // TODO input filter tests
    // TODO validation tests

    public function testCreateActivityIsAllowedForCollaborator() {
        static::createClientWithCredentials()->request('POST', '/activities', ['json' => $this->getExampleWritePayload()]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains($this->getExampleReadPayload());
    }

    public function testCreateActivitySetsCampToCategorysCamp() {
        static::createClientWithCredentials()->request('POST', '/activities', ['json' => $this->getExampleWritePayload()]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains(['_links' => [
            'camp' => ['href' => '/camps/'.static::$fixtures['camp1']->getId()],
        ]]);
    }

    public function testCreateActivityValidatesMissingCategory() {
        static::createClientWithCredentials()->request('POST', '/activities', ['json' => $this->getExampleWritePayload([], ['category'])]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertJsonContains([
            'violations' => [
                [
                    'propertyPath' => 'category',
                    'message' => 'This value should not be null.',
                ],
            ],
        ]);
    }

    public function testCreateActivityValidatesMissingTitle() {
        static::createClientWithCredentials()->request('POST', '/activities', ['json' => $this->getExampleWritePayload([], ['title'])]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertJsonContains([
            'violations' => [
                [
                    'propertyPath' => 'title',
                    'message' => 'This value should not be null.',
                ],
            ],
        ]);
    }

    public function testCreateActivityAllowsMissingLocation() {
        static::createClientWithCredentials()->request('POST', '/activities', ['json' => $this->getExampleWritePayload([], ['location'])]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains(['location' => '']);
    }

    public function getExampleWritePayload($attributes = [], $except = []) {
        return $this->getExamplePayload(
            Activity::class,
            OperationType::COLLECTION,
            'post',
            array_merge(['category' => $this->getIriFor('category1')], $attributes),
            [],
            $except
        );
    }

    public function getExampleReadPayload($attributes = [], $except = []) {
        return $this->getExamplePayload(
            Activity::class,
            OperationType::ITEM,
            'get',
            array_merge([
                '_links' => [
                    'contentNodes' => [],
                ],
            ], $attributes),
            ['category'],
            $except
        );
    }
}
