<?php

namespace Tests\Feature;

use App\Enums\InvoiceSource;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Счёт на оплату (SRS §8.10, UC-035): загрузка PDF в админке, «Скачать счёт» у клиента,
 * «Счёт ещё не сформирован» (ERR-024), источник счёта (вручную / 1С), доступ к чужим счетам.
 */
class OrderInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(OrderInvoice::DISK);

        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->order = Order::create([
            'number' => 'SM-2026-000020', 'user_id' => $this->client->id, 'organization_id' => $this->client->organization_id,
            'status' => 'confirmed', 'total' => 1, 'placed_at' => now(),
        ]);
    }

    private function pdf(string $name = 'schet.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function upload(?UploadedFile $file = null)
    {
        return $this->actingAs($this->admin)->from("/admin/order/{$this->order->id}")
            ->post("/admin/order/{$this->order->id}/invoice", ['invoice' => $file ?? $this->pdf()]);
    }

    public function test_client_sees_not_ready_until_invoice_uploaded(): void
    {
        $this->actingAs($this->client)->get("/order/{$this->order->id}")
            ->assertOk()
            ->assertSee('Счёт ещё не сформирован')
            ->assertDontSee('data-invoice-download', false);

        $this->actingAs($this->client)->get("/order/{$this->order->id}/invoice")
            ->assertRedirect("/order/{$this->order->id}")
            ->assertSessionHas('error', 'Счёт ещё не сформирован');
    }

    public function test_admin_uploads_pdf_and_client_downloads(): void
    {
        $this->upload()->assertRedirect("/admin/order/{$this->order->id}")->assertSessionHas('success');

        $invoice = $this->order->invoice()->firstOrFail();
        $this->assertSame(InvoiceSource::Manual, $invoice->source);
        $this->assertSame($this->admin->id, $invoice->uploaded_by);
        $this->assertSame('schet.pdf', $invoice->original_name);
        Storage::disk(OrderInvoice::DISK)->assertExists($invoice->path);

        $this->actingAs($this->admin)->get("/admin/order/{$this->order->id}")
            ->assertSee('schet.pdf')
            ->assertSee('загружен вручную');

        $this->actingAs($this->client)->get("/order/{$this->order->id}")
            ->assertSee('data-invoice-download', false)
            ->assertDontSee('Счёт ещё не сформирован');

        $response = $this->actingAs($this->client)->get("/order/{$this->order->id}/invoice")->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('SM-2026-000020', rawurldecode($response->headers->get('Content-Disposition')));
    }

    public function test_colleague_can_download_foreign_organization_cannot(): void
    {
        $this->upload();

        $colleague = User::factory()->create(['organization_id' => $this->client->organization_id]);
        $this->actingAs($colleague)->get("/order/{$this->order->id}/invoice")->assertOk();

        $stranger = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($stranger)->get("/order/{$this->order->id}/invoice")->assertNotFound();

        $this->post('/logout');
        $this->get("/order/{$this->order->id}/invoice")->assertRedirect('/login');
    }

    public function test_file_is_not_public(): void
    {
        $this->upload();
        $invoice = $this->order->invoice;

        $this->assertSame('local', OrderInvoice::DISK);
        $this->assertStringStartsWith('invoices/'.$this->order->id.'/', $invoice->path);
        // Закрытый диск: по прямой ссылке файл не отдаётся
        $this->assertContains($this->get('/storage/'.$invoice->path)->status(), [403, 404]);
    }

    public function test_only_pdf_is_accepted_and_only_admin_uploads(): void
    {
        $this->upload(UploadedFile::fake()->image('photo.jpg'))->assertSessionHasErrors(['invoice' => 'Счёт — только PDF']);
        $this->upload(UploadedFile::fake()->create('big.pdf', OrderInvoice::MAX_KB + 1, 'application/pdf'))->assertSessionHasErrors('invoice');
        $this->assertNull($this->order->invoice()->first());

        $this->actingAs($this->client)->post("/admin/order/{$this->order->id}/invoice", ['invoice' => $this->pdf()])->assertForbidden();
        $this->actingAs($this->client)->post("/admin/order/{$this->order->id}/invoice/delete")->assertForbidden();
        $this->actingAs($this->client)->get("/admin/order/{$this->order->id}/invoice")->assertForbidden();
    }

    public function test_new_invoice_replaces_old_and_delete_removes_file(): void
    {
        $this->upload($this->pdf('first.pdf'));
        $old = $this->order->invoice()->first()->path;

        $this->upload($this->pdf('second.pdf'))->assertSessionHas('success', 'Счёт заменён');

        $this->assertSame(1, OrderInvoice::count());
        $invoice = $this->order->invoice()->first();
        $this->assertSame('second.pdf', $invoice->original_name);
        Storage::disk(OrderInvoice::DISK)->assertMissing($old);

        $this->actingAs($this->admin)->post("/admin/order/{$this->order->id}/invoice/delete")->assertSessionHas('success');
        $this->assertSame(0, OrderInvoice::count());
        Storage::disk(OrderInvoice::DISK)->assertMissing($invoice->path);

        $this->actingAs($this->client)->get("/order/{$this->order->id}")->assertSee('Счёт ещё не сформирован');
    }

    public function test_invoice_from_erp_is_supported_by_model(): void
    {
        $invoice = OrderInvoice::attach($this->order, $this->pdf('1c.pdf'), InvoiceSource::Erp, externalId: 'СЧ-000123');

        $this->assertSame(InvoiceSource::Erp, $invoice->source);
        $this->assertSame('СЧ-000123', $invoice->external_id);
        $this->assertNull($invoice->uploaded_by);

        $this->actingAs($this->admin)->get("/admin/order/{$this->order->id}")->assertSee('из 1С');
        $this->actingAs($this->client)->get("/order/{$this->order->id}/invoice")->assertOk();
    }
}
