<?php

describe('GraphQL Feature Gate', function () {
    it('returns 404 when graphql is disabled', function () {
        config(['graphql.enabled' => false]);

        $user = createUser();
        $key = createApiKey($user);

        graphQL('{ me { id name } }', [], $key['plaintext'])
            ->assertStatus(404);
    });

    it('returns 200 with valid key when graphql is enabled', function () {
        config(['graphql.enabled' => true]);

        $user = createUser();
        $key = createApiKey($user);

        graphQL('{ me { id name } }', [], $key['plaintext'])
            ->assertStatus(200)
            ->assertJsonPath('data.me.name', $user->name);
    });

    it('returns 401 without api key', function () {
        config(['graphql.enabled' => true]);

        graphQL('{ me { id name } }')
            ->assertStatus(401);
    });

    it('returns 401 with invalid api key', function () {
        config(['graphql.enabled' => true]);

        graphQL('{ me { id name } }', [], 'sk_invalid_key_that_does_not_exist')
            ->assertStatus(401);
    });

    it('returns 401 with non-sk_ bearer token', function () {
        config(['graphql.enabled' => true]);

        graphQL('{ me { id name } }', [], 'some_other_token_format')
            ->assertStatus(401);
    });
});
