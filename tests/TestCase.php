<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * All documents of a collection the user pulls, page by page with the checkpoint like the
     * client (one page holds at most 500, the standard products alone are more).
     *
     * @return list<array<string, mixed>>
     */
    protected function pullAll(User $user, string $collection): array
    {
        $documents = [];
        $checkpoint = [];
        do {
            $page = $this->actingAs($user)->getJson("/sync/{$collection}/pull?".http_build_query(['limit' => 500, ...$checkpoint]))->assertOk()->json();
            $documents = [...$documents, ...$page['documents']];
            $checkpoint = $page['checkpoint'] ?? [];
        } while (count($page['documents']) === 500);

        return $documents;
    }
}
