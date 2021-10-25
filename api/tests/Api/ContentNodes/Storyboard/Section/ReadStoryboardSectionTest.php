<?php

namespace App\Tests\Api\ContentNodes\Storyboard\Section;

use App\Entity\BaseEntity;
use App\Entity\ContentNode\StoryboardSection;
use App\Entity\User;
use App\Tests\Api\ECampApiTestCase;

/**
 * @internal
 */
class ReadStoryboardSectionTest extends ECampApiTestCase {
    protected StoryboardSection $defaultEntity;

    protected string $endpoint = '';

    public function setUp(): void {
        parent::setUp();

        $this->endpoint = 'storyboard_sections';
        $this->defaultEntity = static::$fixtures['storyboardSection1'];
    }

    public function testGetSection() {
        // given
        /** @var StoryboardSection $entity */
        $section = $this->defaultEntity;

        // when
        $this->get($section);

        // then
        $this->assertResponseStatusCodeSame(200);

        $this->assertJsonContains([
            'id' => $section->getId(),
            'column1' => $section->column1,
            'column2' => $section->column2,
            'column3' => $section->column3,

            '_links' => [
                'storyboard' => ['href' => $this->getIriFor($section->storyboard)],
            ],
        ]);
    }

    /**
     * Standard security checks.
     */
    public function testGetIsDeniedForAnonymousUser() {
        static::createBasicClient()->request('GET', "/content_node/{$this->endpoint}/".$this->defaultEntity->getId());
        $this->assertResponseStatusCodeSame(401);
        $this->assertJsonContains([
            'code' => 401,
            'message' => 'JWT Token not found',
        ]);
    }

    public function testGetIsDeniedForInvitedCollaborator() {
        $this->get(user: static::$fixtures['user6invited']);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetIsDeniedForInactiveCollaborator() {
        $this->get(user: static::$fixtures['user5inactive']);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetIsDeniedForUnrelatedUser() {
        $this->get(user: static::$fixtures['user4unrelated']);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetIsAllowedForGuest() {
        $this->get(user: static::$fixtures['user3guest']);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testGetIsAllowedForMember() {
        $this->get(user: static::$fixtures['user2member']);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testGetIsAllowedForManager() {
        $this->get(user: static::$fixtures['user1manager']);
        $this->assertResponseStatusCodeSame(200);
    }

    protected function get(?BaseEntity $entity = null, ?User $user = null) {
        $credentials = null;
        if (null !== $user) {
            $credentials = ['username' => $user->getUsername()];
        }

        $entity ??= $this->defaultEntity;

        static::createClientWithCredentials($credentials)->request('GET', "/content_node/{$this->endpoint}/".$entity->getId());
    }
}
