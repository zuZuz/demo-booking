<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE bookings
                ALTER COLUMN starts_at TYPE timestamptz(0) USING starts_at AT TIME ZONE 'UTC',
                ALTER COLUMN ends_at TYPE timestamptz(0) USING ends_at AT TIME ZONE 'UTC',
                ADD CONSTRAINT bookings_valid_duration CHECK (
                    ends_at > starts_at
                    AND ends_at - starts_at BETWEEN INTERVAL '15 minutes' AND INTERVAL '4 hours'
                )
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE bookings
                DROP CONSTRAINT bookings_valid_duration,
                ALTER COLUMN starts_at TYPE timestamp(0) without time zone USING starts_at AT TIME ZONE 'UTC',
                ALTER COLUMN ends_at TYPE timestamp(0) without time zone USING ends_at AT TIME ZONE 'UTC'
            SQL);
    }
};
