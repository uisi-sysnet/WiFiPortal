<?php

namespace App\Http\Controllers;

use App\Models\HotspotGuest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GuestController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:connected,expired,revoked'],
        ]);
        $search = trim($filters['q'] ?? '');
        $status = $filters['status'] ?? null;

        $guests = $this->guestQuery($search, $status)
            ->paginate(20)
            ->withQueryString();

        return view('guests.index', [
            'guests' => $guests,
            'search' => $search,
            'status' => $status,
            'counts' => [
                'all' => HotspotGuest::count(),
                'connected' => HotspotGuest::whereNotNull('connected_at')->whereNull('revoked_at')->count(),
                'revoked' => HotspotGuest::whereNotNull('revoked_at')->count(),
            ],
            'exportColumns' => array_column(self::exportColumns(), 0),
        ]);
    }

    public function export(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:connected,expired,revoked'],
        ]);
        $allColumns = self::exportColumns();
        $selected = $request->input('columns', array_keys($allColumns));
        if (! is_array($selected)) {
            throw ValidationException::withMessages(['columns' => 'Choose columns to export.']);
        }
        $selected = array_values(array_unique(array_map('intval', array_filter(
            $selected,
            fn ($column) => filter_var($column, FILTER_VALIDATE_INT) !== false
                && (int) $column >= 0
                && (int) $column < count($allColumns)
        ))));
        if (! $selected) {
            throw ValidationException::withMessages(['columns' => 'Select at least one column to export.']);
        }

        $columns = array_values(array_intersect_key($allColumns, array_flip($selected)));
        $guests = $this->guestQuery(trim($filters['q'] ?? ''), $filters['status'] ?? null)->get();
        $filename = 'guests-'.now()->format('Y-m-d-His').'.xls';

        return response()->streamDownload(function () use ($guests, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
            fwrite($out, '<?mso-application progid="Excel.Sheet"?>'."\n");
            fwrite($out, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
                .' xmlns:o="urn:schemas-microsoft-com:office:office"'
                .' xmlns:x="urn:schemas-microsoft-com:office:excel"'
                .' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n");
            fwrite($out, '<Styles><Style ss:ID="hdr"><Font ss:Bold="1"/><Interior ss:Color="#EAF6F1" ss:Pattern="Solid"/></Style></Styles>'."\n");
            fwrite($out, '<Worksheet ss:Name="Guests"><Table>'."\n");

            foreach ($columns as [$_, $width, $__]) {
                fwrite($out, '<Column ss:Width="'.$width.'"/>');
            }
            fwrite($out, "\n<Row ss:StyleID=\"hdr\">");
            foreach ($columns as [$header]) {
                fwrite($out, '<Cell><Data ss:Type="String">'.htmlspecialchars($header, ENT_XML1).'</Data></Cell>');
            }
            fwrite($out, '</Row>'."\n");

            foreach ($guests as $index => $guest) {
                fwrite($out, '<Row>');
                foreach ($columns as [$_, $__, $value]) {
                    $cell = $value($guest, $index);
                    $numeric = is_int($cell) || is_float($cell);
                    $type = $numeric ? 'Number' : 'String';
                    fwrite($out, '<Cell><Data ss:Type="'.$type.'">'
                        .htmlspecialchars($numeric ? (string) $cell : (string) ($cell ?? ''), ENT_XML1)
                        .'</Data></Cell>');
                }
                fwrite($out, '</Row>'."\n");
            }

            fwrite($out, '</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
                .'<FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal>'
                .'<TopRowBottomPane>1</TopRowBottomPane><ActivePane>2</ActivePane>'
                .'</WorksheetOptions></Worksheet></Workbook>');
            fclose($out);
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function guestQuery(string $search, ?string $status)
    {
        return HotspotGuest::query()
            ->with(['router:id,name', 'network:id,name'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $pattern = '%'.mb_strtolower($search).'%';
                $query->whereRaw('LOWER(name) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(contact) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(mac) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(ip) LIKE ?', [$pattern]);
            }))
            ->when($status === 'connected', fn ($query) => $query->whereNotNull('connected_at')->whereNull('revoked_at'))
            ->when($status === 'revoked', fn ($query) => $query->whereNotNull('revoked_at'))
            ->when($status === 'expired', fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now())->whereNull('revoked_at'))
            ->orderByDesc('created_at');
    }

    private static function accessStatus(HotspotGuest $guest): string
    {
        return $guest->revoked_at ? 'revoked'
            : (($guest->expires_at && $guest->expires_at->isPast()) ? 'expired'
                : ($guest->connected_at ? 'connected' : 'pending'));
    }

    private static function exportColumns(): array
    {
        return [
            ['No.', 45, fn ($guest, $index) => $index + 1],
            ['Guest name', 150, fn ($guest) => $guest->name],
            ['Guest type', 90, fn ($guest) => $guest->resident ? 'Resident' : 'Visitor'],
            ['Contact', 180, fn ($guest) => $guest->contact],
            ['Contact type', 90, fn ($guest) => $guest->contact_type],
            ['Citizen number', 120, fn ($guest) => $guest->citizen_number],
            ['Username', 130, fn ($guest) => $guest->username],
            ['MAC address', 130, fn ($guest) => $guest->mac],
            ['IP address', 110, fn ($guest) => $guest->ip],
            ['Network', 140, fn ($guest) => $guest->network?->name],
            ['Router', 140, fn ($guest) => $guest->router?->name],
            ['Login method', 100, fn ($guest) => $guest->login_method],
            ['Registered', 150, fn ($guest) => $guest->created_at?->toDateTimeString()],
            ['Connected', 150, fn ($guest) => $guest->connected_at?->toDateTimeString()],
            ['Expires', 150, fn ($guest) => $guest->expires_at?->toDateTimeString()],
            ['Revoked', 150, fn ($guest) => $guest->revoked_at?->toDateTimeString()],
            ['Access status', 100, fn ($guest) => self::accessStatus($guest)],
            ['Terms hash', 150, fn ($guest) => $guest->terms_hash],
            ['Updated', 150, fn ($guest) => $guest->updated_at?->toDateTimeString()],
        ];
    }
}
