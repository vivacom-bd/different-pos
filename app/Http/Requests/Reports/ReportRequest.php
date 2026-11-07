<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Models\Branch;
use App\Models\User;
use App\Reports\ReportContract;
use App\Reports\ReportParameters;
use App\Support\Export\ExportFormat;
use App\Support\Sales\BusinessDay;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string of a report page / export: `format` (export only), `from`, `to` (YYYY-MM-DD, tenant days), `branch`
 * (`current`, `all` or a branch id) and the report's own filters. Without reports.all_branches the
 * branch is always the current one.
 */
final class ReportRequest extends FormRequest
{
    public const int MAX_DAYS = 366;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('reports.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'string', ExportFormat::rule()],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'string', 'max:10'],
            'filter' => ['nullable', 'array'],
            'filter.*' => ['nullable', 'string', 'max:40'],
        ];
    }

    /** `format` = csv (default) | xlsx | pdf, for the export route. */
    public function exportFormat(): ExportFormat
    {
        return ExportFormat::fromInput($this->input('format'));
    }

    public function parameters(ReportContract $report): ReportParameters
    {
        $context = app(TenantContext::class);
        $user = $this->user();
        $timezone = BusinessDay::timezone($context->tenant());
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $from = $this->string('from')->toString();
        $to = $this->string('to')->toString();
        $from = $from !== '' ? $from : $today->startOfMonth()->format('Y-m-d');
        $to = $to !== '' ? $to : $today->format('Y-m-d');
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_DAYS) {
            $from = CarbonImmutable::parse($to)->subDays(self::MAX_DAYS)->format('Y-m-d');
        }

        $filters = [];
        $input = $this->input('filter');
        foreach ($report->filters() as $key) {
            $value = is_array($input) ? ($input[$key] ?? null) : null;
            if (is_string($value) && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return new ReportParameters(
            $from,
            $to,
            $timezone,
            $this->branchIds($user instanceof User && $user->can('reports.all_branches'), $context),
            $user instanceof User && $user->can('reports.profit'),
            $filters,
        );
    }

    /** @return list<int>|null */
    private function branchIds(bool $allBranches, TenantContext $context): ?array
    {
        $current = [$context->branchId()];
        if (! $allBranches) {
            return $current;
        }

        $choice = $this->string('branch')->toString();
        if ($choice === 'all') {
            return null;
        }
        if (ctype_digit($choice) && Branch::query()->whereKey((int) $choice)->where('is_active', true)->exists()) {
            return [(int) $choice];
        }

        return $current;
    }
}
