<?php

namespace Tests\Unit;

use App\Support\AuditActions;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AuditActionsTest extends TestCase
{
    /**
     * Every literal action handed to the audit trail has a name on the Audit
     * Log page. Actions built at runtime are listed in the catalogue by hand.
     */
    public function test_every_audited_action_in_the_code_is_listed(): void
    {
        $missing = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            preg_match_all(
                // The closing quote followed by a comma or bracket, so a name
                // finished at runtime ('collection.donation_'.$status) is not read.
                "/(?:auditLogger|audit)->record\\(\\s*[^,]+?,\\s*'([a-z_]+\\.[a-z_.]+)'\\s*[,)]/s",
                (string) file_get_contents($file->getPathname()),
                $matches
            );

            foreach ($matches[1] as $action) {
                if (! AuditActions::has($action)) {
                    $missing[] = $action;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Name these actions in App\\Support\\AuditActions.');
    }

    public function test_an_unlisted_action_still_reads_from_its_own_name(): void
    {
        $this->assertSame([
            'module' => 'donors',
            'module_label' => 'Donors',
            'type' => 'export',
            'type_label' => 'Export',
            'label' => 'Notes exported',
        ], AuditActions::describe('donor.notes_exported'));
    }

    public function test_search_words_find_their_actions(): void
    {
        $this->assertContains('auth.signed_in', AuditActions::matching('Login'));
        $this->assertContains('auth.sign_in_failed', AuditActions::matching('failed sign-in'));
        $this->assertContains('donors.exported', AuditActions::matching('export'));
        $this->assertSame([], AuditActions::matching('  '));
    }
}
