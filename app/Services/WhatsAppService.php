<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class WhatsAppService
{
    protected string $apiUrl;
    protected string $bearerToken;
    protected string $apiKey;
    protected string $phoneNoId;
    public function __construct()
    {
        $this->apiUrl = config('services.whatsapp.api_url');
        $this->bearerToken = config('services.whatsapp.bearer_token');
        $this->apiKey = config('services.whatsapp.api_key');
        $this->phoneNoId = config('services.whatsapp.phone_no_id');
    }
    /**
     * Send any approved WhatsApp template message.
     *
     * @param string      $to                International format, no '+', e.g. 918668072141
     * @param string      $templateName      e.g. 'fylbirds_otp', 'payment_invoice_success'
     * @param string      $language          e.g. 'en_US', 'en'
     * @param array       $bodyParams        Positional values for {{1}}, {{2}}...
     * @param array       $buttons           Optional. Each: ['type' => 'button', 'sub_type' => 'url', 'text' => '...']
     * @param string|null $documentUrl       Optional. Public/signed URL for templates whose header is a document
     *                                       (e.g. an invoice PDF).
     * @param string|null $documentFilename  Optional. Display filename for the attached document.
     */
    public function sendTemplateMessage(
        string $to,
        string $templateName,
        string $language,
        array $bodyParams = [],
        array $buttons = [],
        ?string $documentUrl = null,
        ?string $documentFilename = null
    ): bool {
        // FIX: a null (or otherwise non-string) body param used to be
        // forwarded as-is. rcloud's API expects every body parameter to be
        // a plain string and crashes with "Cannot read properties of null
        // (reading 'type')" if any one of them is null — it likely treats
        // each param as an object and reads a `.type` field off it, and
        // `typeof null === 'object'` in JS lets a null slip past a naive
        // truthy check before that read happens. Sanitizing here means a
        // caller forgetting to guard against a null/empty value (like a
        // missing date) can never again silently break a live customer
        // message — it degrades to an empty string and gets logged instead.
        $sanitizedBodyParams = array_map(function ($param) use ($templateName) {
            if ($param === null) {
                Log::warning('WhatsApp template called with a null body parameter — sending empty string instead.', [
                    'template' => $templateName,
                ]);
                return '';
            }
            return (string) $param;
        }, $bodyParams);
        $payload = [
            'to' => $to,
            'phoneNoId' => $this->phoneNoId,
            'type' => 'template',
            'name' => $templateName,
            'language' => $language,
            'bodyParams' => $sanitizedBodyParams,
        ];
        if (!empty($buttons)) {
            $payload['buttons'] = $buttons;
        }
        // FIX: rcloud's confirmed schema (per "Send Template Message - With
        // Header Image" in their Postman collection) attaches template
        // header media as a typed object inside a `headerParams` array —
        // e.g. [{"type":"image","url":"..."}] — the same array shape used
        // for body params, NOT as flat top-level `url`/`filename` fields.
        // Those flat fields only belong to the unrelated standalone
        // "Send Document Message" endpoint (a plain, non-template message).
        // Sending them at the top level on a `type: template` call is what
        // produced Meta's (#132012) "Parameter format does not match
        // format in the created template" error — the API saw a template
        // request with no recognizable header parameter for it at all.
        if ($documentUrl) {
            $payload['headerParams'] = [
                [
                    'type'     => 'document',
                    'url'      => $documentUrl,
                    'filename' => $documentFilename ?? ($templateName . '.pdf'),
                ],
            ];
        }
        // DEBUG: log the exact payload as it will be JSON-encoded and sent,
        // so a failed send can be diffed byte-for-byte against a known-good
        // curl body. Remove once the #132012 mismatch is root-caused.
        Log::info('WhatsApp outgoing payload', ['payload' => $payload]);
        $response = Http::withToken($this->bearerToken)
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->acceptJson()
            ->post($this->apiUrl, $payload);
        if ($response->failed()) {
            Log::error('WhatsApp template send failed', [
                'template' => $templateName,
                'to' => $to,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);
            return false;
        }
        return true;
    }
}