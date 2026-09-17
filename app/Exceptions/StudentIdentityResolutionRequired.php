<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Collection;

/**
 * Finance UAT corrective (P0) — thrown by QuickStudentRegistrationService::
 * register() BEFORE Student::create() (and therefore before any Enrollment/
 * Invoice/InvoicePayment/FinanceCollection/CashTransaction write) whenever
 * StudentIdentityResolver finds one or more existing Students whose
 * normalized identity matches the submission. Mirrors the exact same
 * shape/handling convention as DuplicateOpenInvoiceException: the
 * controller catches this and renders a resolution state instead of
 * proceeding — it never reaches Laravel's default exception handler.
 *
 * Carries a fresh confirmationToken (see StudentIdentityResolver::
 * issueConfirmationToken()) bound to the exact identity that produced these
 * candidates, so the operator's "continue as a different new student"
 * resubmission can be verified server-side without a bypass flag.
 */
class StudentIdentityResolutionRequired extends Exception
{
    /**
     * @param  Collection<int, array{student_id:int, name:string, phone:?string, strength:string, latest_enrollment_year:?string, latest_enrollment_class:?string, has_current_year_enrollment:bool}>  $candidates
     */
    public function __construct(
        public readonly Collection $candidates,
        public readonly string $confirmationToken,
    ) {
        parent::__construct('Возможно, ученик уже существует — требуется решение оператора.');
    }
}
