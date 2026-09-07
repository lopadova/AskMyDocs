<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillMailboxClient;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Support\FolderCollection;

final class ImapBackfillSelectableMailboxesTest extends TestCase
{
    public function test_discovery_skips_non_selectable_containers_but_keeps_children_and_utf8_paths(): void
    {
        $raw = $this->createMock(SelectableMailboxTestClient::class);
        $folders = new FolderCollection([
            new Folder($raw, 'INBOX', '/', []),
            new Folder($raw, '[Gmail]', '/', ['\\Noselect', '\\HasChildren']),
            new Folder($raw, '[Gmail]/Tutti i messaggi', '/', []),
            new Folder($raw, 'Archivio', '/', ['\\NoSelect', '\\HasChildren']),
            new Folder($raw, 'Archivio/Attivit&AOA-', '/', []),
            new Folder($raw, 'Progetti', '/', ['\\HasChildren']),
            new Folder($raw, 'Progetti/2026', '/', []),
        ]);
        $raw->expects($this->once())->method('getFolders')->with(false)->willReturn($folders);
        // Listing must not try SELECT/STATUS on each container or descendant.
        $raw->expects($this->never())->method('getFolder');
        $raw->expects($this->never())->method('openFolder');
        $inner = $this->createMock(ImapClientInterface::class);
        $inner->expects($this->once())->method('ping')->willReturn(true);
        $inner->expects($this->never())->method('listMailboxes');
        $client = new ImapBackfillMailboxClient($raw, $inner);

        $this->assertSame([
            'INBOX', '[Gmail]/Tutti i messaggi', 'Archivio/Attività', 'Progetti', 'Progetti/2026',
        ], $client->mailboxes());
    }

    public function test_a_list_with_only_containers_has_no_importable_mailboxes(): void
    {
        $raw = $this->createStub(SelectableMailboxTestClient::class);
        $raw->method('getFolders')->willReturn(new FolderCollection([
            new Folder($raw, '[Gmail]', '/', ['\\Noselect']),
        ]));
        $inner = $this->createStub(ImapClientInterface::class);
        $inner->method('ping')->willReturn(true);

        $this->assertSame([], (new ImapBackfillMailboxClient($raw, $inner))->mailboxes());
    }

    public function test_listing_errors_are_not_disguised_as_an_empty_mailbox_list(): void
    {
        $raw = $this->createStub(SelectableMailboxTestClient::class);
        $raw->method('getFolders')->willThrowException(new RuntimeException('LIST failed'));
        $inner = $this->createStub(ImapClientInterface::class);
        $inner->method('ping')->willReturn(true);

        $this->expectExceptionMessage('LIST failed');
        (new ImapBackfillMailboxClient($raw, $inner))->mailboxes();
    }

    public function test_failed_connection_does_not_proceed_with_listing(): void
    {
        $raw = $this->createMock(SelectableMailboxTestClient::class);
        $raw->expects($this->never())->method('getFolders');
        $inner = $this->createStub(ImapClientInterface::class);
        $inner->method('ping')->willReturn(false);

        $this->expectException(RuntimeException::class);
        (new ImapBackfillMailboxClient($raw, $inner))->mailboxes();
    }
}

class SelectableMailboxTestClient extends Client
{
    public function __destruct() {}
}
