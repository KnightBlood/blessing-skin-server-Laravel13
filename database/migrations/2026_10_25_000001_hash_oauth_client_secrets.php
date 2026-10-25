<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Passport 13 always hashes OAuth client secrets, so any secret that is still
 * stored in plain text (created while the application ran on Passport <= 12)
 * would no longer validate and third-party OAuth logins would break.
 *
 * This migration mirrors `php artisan passport:hash`: it is idempotent, it
 * keeps already hashed secrets untouched and it never touches the schema.
 *
 * See UPGRADE-TASKS.md (D8) for the reasoning behind this migration.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('oauth_clients') || !Schema::hasColumn('oauth_clients', 'secret')) {
            return;
        }

        // `cursor()` must not be used here: the same table is updated while
        // iterating, which is not allowed on unbuffered MySQL result sets.
        $clients = DB::table('oauth_clients')
            ->whereNotNull('secret')
            ->where('secret', '<>', '')
            ->get(['id', 'secret']);

        foreach ($clients as $client) {
            $secret = (string) $client->secret;

            if (Hash::isHashed($secret) && !Hash::needsRehash($secret)) {
                continue;
            }

            DB::table('oauth_clients')
                ->where('id', $client->id)
                ->update(['secret' => Hash::make($secret)]);
        }
    }

    public function down(): void
    {
        // Hashing is one-way and irreversible on purpose.
    }
};
