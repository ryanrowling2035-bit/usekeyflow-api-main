<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert all status display values to constant keys in the database.
     * e.g. 'Active' → 'active', 'Upcoming' → 'upcoming', etc.
     */
    public function up(): void
    {
        // Update column defaults to use keys
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });
        Schema::table('manage_emails', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });
        Schema::table('property_categories', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });

        // ==================== USERS ====================
        $this->convertValues('users', 'status', [
            'Active' => 'active',
            'InActive' => 'inActive',
        ]);

        // ==================== TEAMS ====================
        $this->convertValues('teams', 'status', [
            'Active' => 'active',
            'InActive' => 'inActive',
        ]);

        // ==================== MANAGE EMAILS ====================
        $this->convertValues('manage_emails', 'status', [
            'Active' => 'active',
            'InActive' => 'inActive',
        ]);

        // ==================== ROLES ====================
        $this->convertValues('roles', 'status', [
            'Active' => 'active',
            'Inactive' => 'inactive',
        ]);

        // ==================== PROPERTIES - status ====================
        $this->convertValues('properties', 'status', [
            'Upcoming' => 'upcoming',
            'Permit Requested' => 'permitRequested',
            'Draft' => 'draft',
            'Pending' => 'pending',
            'Approved' => 'approved',
            'Published' => 'published',
            'Rejected' => 'rejected',
            'Unpublished' => 'unpublished',
            'Under Offer' => 'underOffer',
            'Rented By' => 'rentedBy',
            'Pocket Listing' => 'pocketListing',
            'Ready' => 'ready',
            'Completed' => 'completed',
        ]);

        // ==================== PROPERTIES - reraPermitNo ====================
        $this->convertValues('properties', 'reraPermitNo', [
            'Not Added' => 'notAdded',
            'Admin Request' => 'adminRequest',
            'Manager Approval' => 'managerApproval',
        ]);

        // ==================== ENQUIRIES ====================
        $this->convertValues('enquiries', 'status', [
            'New Lead' => 'newLead',
            'Property Search' => 'propertySearch',
            'Thinking about it' => 'thinkingAboutIt',
            'Under Offer' => 'underOffer',
            'Sale Progressing' => 'saleProgressing',
            'Sold' => 'sold',
            'Lost' => 'lost',
        ]);

        // ==================== TASKS ====================
        $this->convertValues('tasks', 'status', [
            'Pending' => 'pending',
            'Edit Comment' => 'editComment',
            'Change Due Date' => 'changeDueDate',
            'Close & Follow Up' => 'closeFollowUp',
            'Done' => 'done',
        ]);

        // ==================== OFFERS ====================
        $this->convertValues('offers', 'status', [
            'New' => 'new',
            'Accepted' => 'accepted',
            'Rejected' => 'rejected',
            'Cancelled' => 'cancelled',
        ]);

        // ==================== CLOSINGS ====================
        $this->convertValues('closings', 'status', [
            'Accepted' => 'accepted',
            'Closed' => 'closed',
            'Cancelled' => 'cancelled',
        ]);

        // ==================== CALENDAR EVENTS ====================
        $this->convertValues('calendar_events', 'status', [
            'Scheduled' => 'scheduled',
            'Completed' => 'completed',
            'Cancelled' => 'cancelled',
            'Overdue' => 'overdue',
        ]);

        // ==================== BOOSTS ====================
        if (Schema::hasTable('boosts')) {
            $this->convertValues('boosts', 'status', [
                'Approved' => 'approved',
                'Gone Live' => 'goneLive',
                'Rejected' => 'rejected',
            ]);
        }

        // ==================== PROPERTY APPROVAL HISTORY ====================
        if (Schema::hasTable('property_approval_histories')) {
            $this->convertValues('property_approval_histories', 'status', [
                'Permit Requested' => 'permitRequested',
                'Permit Entered' => 'permitEntered',
                'Pending Approval' => 'pendingApproval',
                'Approved' => 'approved',
                'Rejected' => 'rejected',
                'Published' => 'published',
            ]);
        }
    }

    /**
     * Reverse: convert keys back to display values.
     */
    public function down(): void
    {
        // Users, Teams, ManageEmails
        foreach (['users', 'teams', 'manage_emails'] as $table) {
            $this->convertValues($table, 'status', [
                'active' => 'Active',
                'inActive' => 'InActive',
            ]);
        }

        // Roles
        $this->convertValues('roles', 'status', [
            'active' => 'Active',
            'inactive' => 'Inactive',
        ]);

        // Properties - status
        $this->convertValues('properties', 'status', [
            'upcoming' => 'Upcoming',
            'permitRequested' => 'Permit Requested',
            'draft' => 'Draft',
            'pending' => 'Pending',
            'approved' => 'Approved',
            'published' => 'Published',
            'rejected' => 'Rejected',
            'unpublished' => 'Unpublished',
            'underOffer' => 'Under Offer',
            'rentedBy' => 'Rented By',
            'pocketListing' => 'Pocket Listing',
            'ready' => 'Ready',
            'completed' => 'Completed',
        ]);

        // Properties - reraPermitNo
        $this->convertValues('properties', 'reraPermitNo', [
            'notAdded' => 'Not Added',
            'adminRequest' => 'Admin Request',
            'managerApproval' => 'Manager Approval',
        ]);

        // Enquiries
        $this->convertValues('enquiries', 'status', [
            'newLead' => 'New Lead',
            'propertySearch' => 'Property Search',
            'thinkingAboutIt' => 'Thinking about it',
            'underOffer' => 'Under Offer',
            'saleProgressing' => 'Sale Progressing',
            'sold' => 'Sold',
            'lost' => 'Lost',
        ]);

        // Tasks
        $this->convertValues('tasks', 'status', [
            'pending' => 'Pending',
            'editComment' => 'Edit Comment',
            'changeDueDate' => 'Change Due Date',
            'closeFollowUp' => 'Close & Follow Up',
            'done' => 'Done',
        ]);

        // Offers
        $this->convertValues('offers', 'status', [
            'new' => 'New',
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
        ]);

        // Closings
        $this->convertValues('closings', 'status', [
            'accepted' => 'Accepted',
            'closed' => 'Closed',
            'cancelled' => 'Cancelled',
        ]);

        // Calendar events
        $this->convertValues('calendar_events', 'status', [
            'scheduled' => 'Scheduled',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'overdue' => 'Overdue',
        ]);

        // Boosts
        if (Schema::hasTable('boosts')) {
            $this->convertValues('boosts', 'status', [
                'approved' => 'Approved',
                'goneLive' => 'Gone Live',
                'rejected' => 'Rejected',
            ]);
        }

        // Property Approval History
        if (Schema::hasTable('property_approval_histories')) {
            $this->convertValues('property_approval_histories', 'status', [
                'permitRequested' => 'Permit Requested',
                'permitEntered' => 'Permit Entered',
                'pendingApproval' => 'Pending Approval',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'published' => 'Published',
            ]);
        }
    }

    /**
     * Convert column values from old to new using CASE WHEN for efficiency.
     */
    private function convertValues(string $table, string $column, array $mapping): void
    {
        if (empty($mapping)) {
            return;
        }

        $cases = '';
        $oldValues = [];

        foreach ($mapping as $old => $new) {
            $cases .= " WHEN '{$old}' THEN '{$new}'";
            $oldValues[] = "'{$old}'";
        }

        $inClause = implode(',', $oldValues);

        DB::statement("UPDATE `{$table}` SET `{$column}` = CASE `{$column}` {$cases} END WHERE `{$column}` IN ({$inClause})");
    }
};
