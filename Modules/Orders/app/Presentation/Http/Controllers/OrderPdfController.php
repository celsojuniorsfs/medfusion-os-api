<?php

namespace Modules\Orders\Presentation\Http\Controllers;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Modules\Orders\Application\RecordOrderPdf;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Infrastructure\ReadModels\OrderPdf;
use Modules\Orders\Presentation\Http\Resources\OrderPdfHistoryResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderPdfController
{
    private const array WITH = ['client', 'equipments.accessories', 'equipments.items', 'items'];

    // Render do dompdf leva poucos segundos; 30s cobre com folga sem travar a OS se o processo morrer.
    private const int LOCK_SECONDS = 30;

    /**
     * `order_equipment_ids` opcional (api#149) — ausente/null gera o orçamento da OS inteira,
     * comportamento original.
     */
    public function store(Request $request, string $id, RecordOrderPdf $recordOrderPdf): JsonResponse
    {
        Order::findOrFail($id);

        $data = $request->validate([
            'order_equipment_ids' => ['nullable', 'array', 'min:1'],
            'order_equipment_ids.*' => ['uuid', Rule::exists('order_equipments', 'id')->where('order_id', $id)],
        ]);

        $orderEquipmentIds = $data['order_equipment_ids'] ?? null;

        // Uma geração por OS de cada vez: sem o lock, dois cliques simultâneos leem o mesmo
        // `pdf_path` anterior e o PDF do primeiro a terminar fica órfão no storage.
        return Cache::lock("orders:{$id}:pdf", self::LOCK_SECONDS)->block(self::LOCK_SECONDS, function () use ($id, $recordOrderPdf, $orderEquipmentIds) {
            $order = Order::with(self::WITH)->findOrFail($id);

            $equipments = $orderEquipmentIds === null
                ? $order->equipments
                : $order->equipments->whereIn('id', $orderEquipmentIds);

            $includedIds = $equipments->pluck('id');
            $items = $order->items->filter(
                fn ($item) => $item->order_equipment_id === null || $includedIds->contains($item->order_equipment_id),
            );
            $laborCost = ($order->labor_cost ?? 0) + $equipments->sum('labor_cost');
            $total = $laborCost + $items->sum(fn ($item) => ($item->unit_price ?? 0) * $item->quantity);

            $html = view('orders::pdf.order', [
                'order' => $order,
                'equipments' => $equipments,
                'items' => $items,
                'laborCost' => $laborCost,
                'total' => $total,
                'logoBase64' => $this->logoBase64(),
            ])->render();

            $dompdf = new Dompdf((new Options)->set('isRemoteEnabled', false));
            $dompdf->loadHtml($html);
            $dompdf->setPaper('a4', 'portrait');
            $dompdf->render();

            $disk = Storage::disk(config('filesystems.default'));
            $path = "orders/{$id}/os-{$order->number}-".now()->format('YmdHis').'.pdf';
            $disk->put($path, $dompdf->output());

            $generatedAt = now()->toIso8601String();
            $recordOrderPdf($id, $path, $generatedAt, $orderEquipmentIds);

            // Arquivos anteriores não são apagados (api#149) — o histórico precisa deles.

            return response()->json($this->payload($id, $path, $generatedAt));
        });
    }

    public function show(string $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        abort_unless($order->pdf_path, 404);

        return response()->json($this->payload($id, $order->pdf_path, $order->pdf_generated_at->toIso8601String()));
    }

    /**
     * GET /orders/{id}/pdf/history (api#149) — cada orçamento já gerado, com os equipamentos
     * incluídos e um link assinado próprio.
     */
    public function history(string $id): JsonResponse
    {
        Order::findOrFail($id);

        $pdfs = OrderPdf::where('order_id', $id)->with('equipments')->orderBy('generated_at', 'desc')->get();

        return response()->json(['data' => OrderPdfHistoryResource::collection($pdfs)]);
    }

    /**
     * Fora de `auth:sanctum`, mesmo padrão de `EquipmentPhotoController::show`: a credencial é a
     * assinatura da URL (middleware `signed`), não um header Authorization.
     */
    public function download(string $id): StreamedResponse
    {
        $order = Order::findOrFail($id);

        abort_unless($order->pdf_path, 404);

        return $this->respondWithFile($order->pdf_path, $order->number);
    }

    public function downloadHistorical(string $id, string $pdfId): StreamedResponse
    {
        $order = Order::findOrFail($id);
        $pdf = OrderPdf::where('order_id', $id)->findOrFail($pdfId);

        return $this->respondWithFile($pdf->path, $order->number);
    }

    private function respondWithFile(string $path, int $number): StreamedResponse
    {
        $disk = Storage::disk(config('filesystems.default'));

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, "OS-{$number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $id, string $path, string $generatedAt): array
    {
        $expiresAt = now()->addMinutes(30);

        return [
            'url' => URL::temporarySignedRoute('orders.pdf.download', $expiresAt, ['id' => $id]),
            'generated_at' => $generatedAt,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * dompdf não busca URL externa por padrão (`isRemoteEnabled` fica false) — o logo precisa
     * entrar como data URI, lido do arquivo copiado pro módulo (ver Modules/Orders/resources/images).
     */
    private function logoBase64(): string
    {
        $path = module_path('Orders', 'resources/images/logo.png');

        return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
    }
}
