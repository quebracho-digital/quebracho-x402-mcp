<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Keeps one screening list's local copy fresh. Keeping lists fresh is the job of the site that uses the
 * library: it calls a ListSync from its own cron (or queue, or deploy), with its own HTTP client, and passes
 * the file to the list (`new OfacSdn($file)`, `new AddressList([$file])`).
 *
 * The library ships two: OfacSdnSync (OFAC's SDN.CSV) and AddressListSync (a plain-text list at a URL). A site
 * with another source (a vendor feed, a database, a file it receives) implements this interface and writes the
 * format the list reads (OfacSdn::write for OFAC, AddressList's text format for the others).
 *
 * The library's maintainers refresh the OFAC copy shipped with each release; that copy only bounds how old the
 * list can be, it is not a way to keep it fresh.
 */
interface ListSync
{
    /**
     * Writes the newest copy of the list to $file, atomically, unless $file already holds a younger one
     * (SyncResult->replaced false): the youngest list wins.
     *
     * @throws \RuntimeException when the source cannot be read or does not look like the list; $file is left as it was
     */
    public function sync(string $file): SyncResult;
}
