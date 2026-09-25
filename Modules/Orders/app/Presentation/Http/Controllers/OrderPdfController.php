<?php

namespace Modules\Orders\Presentation\Http\Controllers;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Orders\Application\RecordOrderPdf;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderPdfController
{
    private const array WITH = ['client', 'equipments', 'items'];

    public function store(string $id, RecordOrderPdf $recordOrderPdf): JsonResponse
    {
        $order = Order::with(self::WITH)->findOrFail($id);

        $html = view('orders::pdf.order', [
            'order' => $order,
            'logoBase64' => $this->logoBase64(),
        ])->render();

        $dompdf = new Dompdf((new Options)->set('isRemoteEnabled', false));
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $path = "orders/{$id}/os-{$order->number}-".now()->format('YmdHis').'.pdf';
        Storage::disk(config('filesystems.default'))->put($path, $dompdf->output());

        $generatedAt = now()->toIso8601String();
        $recordOrderPdf($id, $path, $generatedAt);

        return response()->json($this->payload($id, $path, $generatedAt));
    }

    public function show(string $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        abort_unless($order->pdf_path, 404);

        return response()->json($this->payload($id, $order->pdf_path, $order->pdf_generated_at->toIso8601String()));
    }

    /**
     * Fora de `auth:sanctum`, mesmo padrão de `EquipmentPhotoController::show`: a credencial é a
     * assinatura da URL (middleware `signed`), não um header Authorization.
     */
    public function download(string $id): StreamedResponse
    {
        $order = Order::findOrFail($id);

        abort_unless($order->pdf_path, 404);

        $disk = Storage::disk(config('filesystems.default'));

        abort_unless($disk->exists($order->pdf_path), 404);

        return $disk->response($order->pdf_path, "OS-{$order->number}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
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
