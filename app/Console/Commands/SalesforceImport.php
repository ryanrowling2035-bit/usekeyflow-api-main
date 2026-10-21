<?php

namespace App\Console\Commands;

use App\Services\Salesforce\Importers\AbstractObjectImporter;
use App\Services\Salesforce\Importers\ActivityImporter;
use App\Services\Salesforce\Importers\ClosingImporter;
use App\Services\Salesforce\Importers\ContactImporter;
use App\Services\Salesforce\Importers\EnquiryImporter;
use App\Services\Salesforce\Importers\ListingImporter;
use App\Services\Salesforce\Importers\OfferImporter;
use App\Services\Salesforce\Importers\TaskImporter;
use App\Services\Salesforce\Importers\UserImporter;

/**
 * Imports the DATA records of a Salesforce (Propertybase) Weekly Export. Listing gallery photos
 * are handled by salesforce:import-remote-images, and file blobs (ContentVersion — documents and
 * Files) by salesforce:blobs:import / salesforce:blobs:download; all rely on the id mappings this
 * command records.
 */
class SalesforceImport extends SalesforceImportCommand
{
    protected $signature = 'salesforce:import
        {path : Path to the export — an extracted directory, a single .ZIP, or a folder of multi-part WE_…_N.ZIP files (extracted and merged automatically)}
        {--only= : Comma list of objects to import (user,contact,property,enquiry,offer,closing,task,activity)}
        {--from= : Resume from this object, importing it and every later object in the pipeline}
        {--since= : Incremental import — only rows created or modified on/after this date (YYYY-MM-DD); existing records are updated, new ones created}
        {--limit= : Max rows to read per object (for testing)}
        {--shard= : Import only shard i of N (i/N) of a single object — N instances can run in parallel over disjoint rows. Requires --only=<object>; parallelise objects one at a time with a barrier between them (later objects depend on earlier ones)}
        {--chunk=500 : Insert chunk size}';

    protected $description = 'Import Salesforce (Propertybase) Weekly Export DATA — users, contacts, listings, enquiries, offers, closings, tasks, activities. Media is imported separately with salesforce:import-remote-images and salesforce:blobs:import.';

    /** Dependency order — each step relies on ids mapped by earlier steps. */
    protected function pipeline(): array
    {
        return [
            'user' => UserImporter::class,
            'contact' => ContactImporter::class,
            'property' => ListingImporter::class,
            'enquiry' => EnquiryImporter::class,
            'offer' => OfferImporter::class,
            'closing' => ClosingImporter::class,
            'task' => TaskImporter::class,
            'activity' => ActivityImporter::class,
        ];
    }

    protected function configureImporter(AbstractObjectImporter $importer): void
    {
        if ($this->option('chunk') !== null) {
            $importer->setChunkSize((int) $this->option('chunk'));
        }
        if ($this->option('since') !== null) {
            $importer->setSince((string) $this->option('since'));
        }
    }

    public function handle(): int
    {
        return $this->runPipeline();
    }
}
