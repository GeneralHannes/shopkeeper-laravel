<?php
namespace App\Http\Controllers;

use App\Support\Repo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApiController extends Controller
{
    // ---------- helpers ----------
    private function cur($v): string
    {
        $u = strtoupper(trim((string) ($v ?? 'USD')));
        return in_array($u, ['USD', 'KHR'], true) ? $u : 'USD';
    }

    private function itemDict(object $it, ?array $prices = null): array
    {
        if ($prices === null) {
            $prices = [];
            foreach (['retail', 'pack', 'wholesale', 'cost'] as $k) {
                $p = Repo::currentPrice((int) $it->id, $k);
                if ($p) $prices[$k] = $p;
            }
        }
        $r = $prices['retail'] ?? null; $w = $prices['wholesale'] ?? null;
        $c = $prices['cost'] ?? null; $pk = $prices['pack'] ?? null;
        $retail = $r ? (float) $r->price : null;
        $cost = $c ? (float) $c->price : null;
        $any = $r ?: ($w ?: $c);
        return [
            'id' => (int) $it->id, 'name' => $it->name, 'brand' => $it->brand, 'size' => $it->size,
            'abv' => isset($it->abv) && $it->abv !== null ? (float) $it->abv : null,
            'vintage' => isset($it->vintage) && $it->vintage !== null ? (int) $it->vintage : null,
            'style' => $it->style ?? null, 'origin' => $it->origin ?? null,
            'is_alcohol' => (bool) ($it->is_alcohol ?? false), 'category' => $it->category, 'unit' => $it->unit,
            'supplier' => $it->supplier, 'quantity_on_hand' => (float) $it->quantity_on_hand,
            'retail' => $retail, 'pack' => $pk ? (float) $pk->price : null,
            'wholesale' => $w ? (float) $w->price : null, 'cost' => $cost,
            'margin' => ($retail !== null && $cost !== null) ? round($retail - $cost, 2) : null,
            'currency' => $any ? $any->currency : 'USD', 'price' => $retail, 'has_image' => (bool) ($it->has_image ?? false),
        ];
    }

    private function itemList(array $items): array
    {
        $pmap = Repo::currentPricesFor(array_map(fn ($i) => (int) $i->id, $items));
        return array_map(fn ($i) => $this->itemDict($i, $pmap[(int) $i->id] ?? []), $items);
    }

    private function err(int $status, string $msg)
    {
        return response()->json(['detail' => $msg], $status);
    }

    // ---------- pages / auth ----------
    public function index()
    {
        return response(file_get_contents(resource_path('index.html')))->header('Content-Type', 'text/html');
    }
    public function auth()
    {
        return response()->json(['required' => trim((string) env('WEB_TOKEN', '')) !== '']);
    }

    // ---------- items ----------
    public function items() { return response()->json($this->itemList(Repo::listItems())); }
    public function quick() { return response()->json($this->itemList(Repo::frequentItems(30, 12))); }
    public function search(Request $r) { return response()->json($this->itemList(Repo::findItems((string) $r->query('q', '')))); }

    public function addItem(Request $r)
    {
        $b = $r->all();
        $barcode = trim((string) ($b['barcode'] ?? '')) ?: null;
        $item = Repo::addItem([
            'name' => $b['name'], 'brand' => ($b['brand'] ?? null) ?: null, 'size' => ($b['size'] ?? null) ?: null,
            'abv' => $b['abv'] ?? null, 'vintage' => $b['vintage'] ?? null, 'style' => ($b['style'] ?? null) ?: null,
            'origin' => ($b['origin'] ?? null) ?: null, 'is_alcohol' => (bool) ($b['is_alcohol'] ?? false),
            'category' => $b['category'] ?? null, 'unit' => $b['unit'] ?? 'each', 'supplier' => $b['supplier'] ?? null, 'barcode' => $barcode,
        ]);
        $c = $b['currency'] ?? 'USD';
        if (($b['retail'] ?? null) !== null) Repo::setPrice($item->id, (float) $b['retail'], 'retail', $this->cur($c));
        if (($b['pack'] ?? null) !== null) Repo::setPrice($item->id, (float) $b['pack'], 'pack', $this->cur($b['pack_currency'] ?? $c));
        if (($b['wholesale'] ?? null) !== null) Repo::setPrice($item->id, (float) $b['wholesale'], 'wholesale', $this->cur($b['wholesale_currency'] ?? $c));
        if (($b['cost'] ?? null) !== null) Repo::setPrice($item->id, (float) $b['cost'], 'cost', $this->cur($b['cost_currency'] ?? $c));
        if (!empty($b['stock'])) Repo::restock($item->id, (float) $b['stock']);
        return response()->json($this->itemDict(Repo::getItem($item->id)));
    }

    public function updateMeta(Request $r, int $id)
    {
        $b = $r->all();
        if (!Repo::getItem($id)) return $this->err(404, "no item #$id");
        if (trim((string) ($b['name'] ?? '')) === '') return $this->err(400, 'name cannot be blank');
        Repo::updateItemMeta($id, $b['name'], $b['brand'] ?? null, $b['size'] ?? null, $b['category'] ?? null);
        return response()->json($this->itemDict(Repo::getItem($id)));
    }

    public function updateInfo(Request $r, int $id)
    {
        $b = $r->all();
        if (!Repo::getItem($id)) return $this->err(404, "no item #$id");
        Repo::updateItemInfo($id, $b['abv'] ?? null, $b['vintage'] ?? null, $b['style'] ?? null, $b['origin'] ?? null);
        return response()->json($this->itemDict(Repo::getItem($id)));
    }

    public function restock(Request $r, int $id)
    {
        if (!Repo::getItem($id)) return $this->err(404, "no item #$id");
        Repo::restock($id, (float) $r->input('quantity'));
        return response()->json($this->itemDict(Repo::getItem($id)));
    }

    public function deleteItem(int $id)
    {
        if (!Repo::getItem($id)) return $this->err(404, "no item #$id");
        Repo::deleteItem($id);
        return response()->json(['deleted' => $id]);
    }

    // ---------- quick-add / import ----------
    private function dec(?string $v): ?float
    {
        $s = trim((string) $v);
        if ($s === '') return null;
        if (!is_numeric($s)) throw new RuntimeException('not a number');
        return (float) $s;
    }

    public function quickAdd(Request $r)
    {
        $created = []; $errors = []; $i = 0;
        foreach (preg_split("/\r\n|\n|\r/", (string) $r->input('text', '')) as $raw) {
            $i++; $line = trim($raw); if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));
            $name = $parts[0] ?? '';
            if ($name === '') { $errors[] = ['line' => $i, 'reason' => 'no name']; continue; }
            try { $retail = $this->dec($parts[1] ?? null); $wholesale = $this->dec($parts[2] ?? null); $cost = $this->dec($parts[3] ?? null); $qty = $this->dec($parts[4] ?? null); }
            catch (RuntimeException) { $errors[] = ['line' => $i, 'reason' => 'price/qty must be a number']; continue; }
            $item = Repo::addItem(['name' => $name, 'category' => ($parts[5] ?? null) ?: null, 'unit' => 'each', 'supplier' => ($parts[6] ?? null) ?: null]);
            if ($retail !== null) Repo::setPrice($item->id, $retail, 'retail');
            if ($wholesale !== null) Repo::setPrice($item->id, $wholesale, 'wholesale');
            if ($cost !== null) Repo::setPrice($item->id, $cost, 'cost');
            if ($qty) Repo::restock($item->id, $qty);
            $created[] = $name;
        }
        return response()->json(['created' => $created, 'errors' => $errors]);
    }

    private const IMPORT_COLS = [
        'name' => ['name', 'item', 'product'], 'brand' => ['brand'], 'size' => ['size'],
        'retail' => ['retail', 'price', 'sell'], 'wholesale' => ['wholesale', 'bulk'], 'cost' => ['cost', 'buy'],
        'qty' => ['qty', 'quantity', 'stock'], 'category' => ['category', 'cat'], 'supplier' => ['supplier', 'note'],
        'barcode' => ['barcode', 'code'], 'currency' => ['currency', 'cur'],
    ];
    private const IMPORT_ORDER = ['name', 'retail', 'wholesale', 'cost', 'qty', 'category', 'supplier', 'barcode', 'currency'];

    private function parseCsv(string $text, string $delim): array
    {
        $rows = []; $row = []; $field = ''; $inq = false; $n = strlen($text);
        for ($i = 0; $i < $n; $i++) {
            $ch = $text[$i];
            if ($inq) {
                if ($ch === '"') { if (($text[$i + 1] ?? '') === '"') { $field .= '"'; $i++; } else $inq = false; }
                else $field .= $ch;
            } elseif ($ch === '"') $inq = true;
            elseif ($ch === $delim) { $row[] = $field; $field = ''; }
            elseif ($ch === "\n") { $row[] = $field; $rows[] = $row; $row = []; $field = ''; }
            elseif ($ch === "\r") { /* skip */ }
            else $field .= $ch;
        }
        if ($field !== '' || $row) { $row[] = $field; $rows[] = $row; }
        return array_values(array_filter($rows, fn ($rr) => count(array_filter($rr, fn ($x) => trim($x) !== '')) > 0));
    }

    public function import(Request $r)
    {
        $text = (string) $r->input('text', '');
        $sample = substr($text, 0, 2000);
        $delim = ',';
        foreach (['|', "\t", ','] as $d) if (str_contains($sample, $d)) { $delim = $d; break; }
        $rows = $this->parseCsv($text, $delim);
        if (!$rows) return response()->json(['created' => [], 'errors' => []]);
        $header = null;
        $first = array_map(fn ($x) => strtolower(trim($x)), $rows[0]);
        $isHeader = false;
        foreach ($first as $cx) foreach (self::IMPORT_COLS as $al) if (in_array($cx, $al, true)) { $isHeader = true; break 2; }
        if ($isHeader) { $header = $first; $rows = array_slice($rows, 1); }
        $colIndex = function (string $field) use ($header) {
            if ($header) { foreach (self::IMPORT_COLS[$field] as $al) { $j = array_search($al, $header, true); if ($j !== false) return $j; } return -1; }
            return array_search($field, self::IMPORT_ORDER, true);
        };
        $idx = []; foreach (array_keys(self::IMPORT_COLS) as $f) $idx[$f] = $colIndex($f);
        $cell = fn (array $row, string $field) => ($idx[$field] !== false && $idx[$field] >= 0 && $idx[$field] < count($row)) ? trim($row[$idx[$field]]) : '';
        $created = []; $errors = []; $i = 0;
        foreach ($rows as $row) {
            $i++;
            $brand = $cell($row, 'brand') ?: null; $size = $cell($row, 'size') ?: null;
            $name = $cell($row, 'name') ?: trim(implode(' ', array_filter([$brand, $size])));
            if ($name === '') { $errors[] = ['row' => $i, 'reason' => 'no name']; continue; }
            try { $retail = $this->dec($cell($row, 'retail')); $wholesale = $this->dec($cell($row, 'wholesale')); $cost = $this->dec($cell($row, 'cost')); $qty = $this->dec($cell($row, 'qty')); }
            catch (RuntimeException) { $errors[] = ['row' => $i, 'reason' => 'price/qty not a number']; continue; }
            $cc = $this->cur($cell($row, 'currency'));
            $item = Repo::addItem(['name' => $name, 'brand' => $brand, 'size' => $size, 'category' => $cell($row, 'category') ?: null, 'unit' => 'each', 'supplier' => $cell($row, 'supplier') ?: null, 'barcode' => $cell($row, 'barcode') ?: null]);
            if ($retail !== null) Repo::setPrice($item->id, $retail, 'retail', $cc);
            if ($wholesale !== null) Repo::setPrice($item->id, $wholesale, 'wholesale', $cc);
            if ($cost !== null) Repo::setPrice($item->id, $cost, 'cost', $cc);
            if ($qty) Repo::restock($item->id, $qty);
            $created[] = $name;
        }
        return response()->json(['created' => $created, 'errors' => $errors]);
    }

    // ---------- barcode lookup ----------
    private function extractSize(?string $t): ?string
    {
        if (!$t) return null;
        if (preg_match('/\b(\d+(?:\.\d+)?)\s?(ml|cl|l|litre|liter|g|kg|oz)\b/i', $t, $m)) return $m[1] . strtolower($m[2]);
        return null;
    }
    private function offLookup(string $code): ?array
    {
        try {
            $res = Http::timeout(6)->withHeaders(['User-Agent' => 'shopkeeper/1.0'])
                ->get("https://world.openfoodfacts.org/api/v2/product/$code.json", ['fields' => 'product_name,brands,quantity']);
            if (!$res->ok()) return null;
            $p = $res->json('product') ?? [];
            $name = trim($p['product_name'] ?? ''); if ($name === '') return null;
            return ['name' => $name, 'source' => 'openfoodfacts', 'brand' => trim(explode(',', $p['brands'] ?? '')[0]) ?: null, 'size' => trim($p['quantity'] ?? '') ?: null];
        } catch (\Throwable) { return null; }
    }
    private function upcitemdbLookup(string $code): ?array
    {
        try {
            $res = Http::timeout(8)->withHeaders(['User-Agent' => 'shopkeeper/1.0'])->get("https://api.upcitemdb.com/prod/trial/lookup?upc=$code");
            if (!$res->ok()) return null;
            $items = $res->json('items') ?? []; if (!$items) return null;
            $it = $items[0]; $name = trim($it['title'] ?? ''); if ($name === '') return null;
            return ['name' => $name, 'source' => 'upcitemdb', 'brand' => trim($it['brand'] ?? '') ?: null, 'size' => trim($it['size'] ?? '') ?: null];
        } catch (\Throwable) { return null; }
    }
    private function barcodeLookup(string $code): ?array
    {
        $info = $this->offLookup($code) ?: $this->upcitemdbLookup($code);
        if (!$info) return null;
        $info['size'] = $this->extractSize($info['size'] ?? null) ?: ($this->extractSize($info['name']) ?: ($info['size'] ?? null));
        return $info;
    }
    public function lookupBarcode(string $code)
    {
        $code = trim($code);
        $existing = Repo::getItemByBarcode($code);
        if ($existing) return response()->json(['found' => true, 'in_catalog' => true, 'item' => $this->itemDict($existing), 'barcode' => $code]);
        $info = $this->barcodeLookup($code);
        if ($info) return response()->json(array_merge(['found' => true, 'in_catalog' => false, 'barcode' => $code], $info));
        return response()->json(['found' => false, 'in_catalog' => false, 'barcode' => $code]);
    }
    public function barcode(string $code)
    {
        $item = Repo::getItemByBarcode($code);
        if (!$item) return $this->err(404, 'unknown barcode');
        return response()->json($this->itemDict($item));
    }
    public function setBarcode(Request $r, int $id)
    {
        if (!Repo::getItem($id)) return $this->err(404, "no item #$id");
        try { Repo::setBarcode($id, (string) $r->input('barcode')); }
        catch (RuntimeException $e) { return $this->err(409, $e->getMessage()); }
        return response()->json($this->itemDict(Repo::getItem($id)));
    }

    // ---------- options ----------
    public function options(int $id)
    {
        if (!Repo::getItem($id)) return $this->err(404, "no item #$id");
        return response()->json(Repo::getOptions($id));
    }
    public function addOption(Request $r, int $id)
    {
        $b = $r->all();
        if (!Repo::getItem($id)) return $this->err(404, "no item #$id");
        if (trim((string) ($b['name'] ?? '')) === '') return $this->err(400, 'option name required');
        return response()->json(Repo::addOption($id, $b['name'], (float) $b['price'], isset($b['amount']) ? (float) $b['amount'] : 1, $this->cur($b['currency'] ?? null)));
    }
    public function deleteOption(int $oid) { Repo::deleteOption($oid); return response()->json(['deleted' => $oid]); }

    // ---------- categories ----------
    public function renameCategory(Request $r)
    {
        $nw = trim((string) $r->input('new', ''));
        if ($nw === '') return $this->err(400, 'new category name required');
        $moved = Repo::renameCategory($r->input('old_name'), $nw);
        return response()->json(['moved' => $moved, 'category' => $nw]);
    }

    // ---------- images ----------
    public function addImage(Request $r, int $id)
    {
        if (!Repo::getItem($id)) return $this->err(404, "no item #$id");
        $raw = base64_decode((string) $r->input('data'), true);
        if ($raw === false) return $this->err(400, 'invalid image data');
        if (strlen($raw) > 6_000_000) return $this->err(413, 'image too large (resize on the client)');
        $imageId = Repo::addItemImage($id, $raw, (string) $r->input('content_type', 'image/jpeg'));
        return response()->json(['ok' => true, 'id' => $imageId]);
    }
    public function listImages(int $id) { return response()->json(Repo::listItemImages($id)); }
    public function itemImage(int $id)
    {
        $img = Repo::getItemImage($id);
        if (!$img) return $this->err(404, 'no image');
        return response($img['data'])->header('Content-Type', $img['content_type'])->header('Cache-Control', 'no-cache');
    }
    public function image(int $iid)
    {
        $img = Repo::getImage($iid);
        if (!$img) return $this->err(404, 'no image');
        return response($img['data'])->header('Content-Type', $img['content_type'])->header('Cache-Control', 'public, max-age=31536000, immutable');
    }
    public function deleteImage(int $iid)
    {
        $itemId = Repo::deleteImage($iid);
        if ($itemId === null) return $this->err(404, 'no image');
        return response()->json(['ok' => true, 'item_id' => $itemId]);
    }

    // ---------- prices ----------
    public function setPrice(Request $r, int $id)
    {
        $b = $r->all();
        if (!Repo::getItem($id)) return $this->err(404, "no item #$id");
        $kind = $b['kind'] ?? 'retail';
        if (!in_array($kind, ['retail', 'pack', 'wholesale', 'cost'], true)) return $this->err(400, 'kind must be retail, wholesale, or cost');
        if (!empty($b['currency'])) $curr = $this->cur($b['currency']);
        else { $ex = Repo::currentPrice($id, $kind) ?: Repo::currentPrice($id, 'retail'); $curr = $ex ? $ex->currency : 'USD'; }
        Repo::setPrice($id, (float) $b['price'], $kind, $curr);
        return response()->json($this->itemDict(Repo::getItem($id)));
    }

    // ---------- sales ----------
    public function sale(Request $r)
    {
        $b = $r->all();
        if (empty($b['lines'])) return $this->err(400, 'sale has no lines');
        $lines = [];
        foreach ($b['lines'] as $ln) {
            $item = Repo::getItem((int) $ln['item_id']);
            if (!$item) return $this->err(400, "no item #{$ln['item_id']}");
            $price = Repo::currentPrice((int) $ln['item_id'], $ln['kind'] ?? 'retail') ?: Repo::currentPrice((int) $ln['item_id'], 'retail');
            if (!$price) return $this->err(400, "{$item->name} has no price set");
            $lines[] = ['item_id' => (int) $item->id, 'description' => $item->name, 'quantity' => (float) ($ln['quantity'] ?? 1), 'unit_price' => (float) $price->price];
        }
        $sale = Repo::recordSale($b['payment_method'] ?? 'cash', 'USD', $lines);
        return response()->json(['id' => $sale['id'], 'total' => $sale['total'], 'currency' => $sale['currency'], 'lines' => $sale['lines']]);
    }
    public function voidSale(int $sid)
    {
        try { $s = Repo::voidSale($sid); return response()->json(['id' => $s['id'], 'voided' => true, 'total' => (float) $s['total']]); }
        catch (RuntimeException $e) { return $this->err(400, $e->getMessage()); }
    }

    // ---------- AI (Ollama) ----------
    private function ollama(string $system, string $user, ?array $format = null): string
    {
        $host = env('OLLAMA_HOST', 'http://localhost:11434');
        $model = env('OLLAMA_MODEL', 'qwen2.5:3b-instruct');
        $body = ['model' => $model, 'stream' => false, 'keep_alive' => '30m',
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]]];
        if ($format) $body['format'] = $format;
        $res = Http::timeout(60)->post("$host/api/chat", $body);
        if (!$res->ok()) throw new RuntimeException('ollama ' . $res->status());
        return $res->json('message.content') ?? '';
    }
    private function aiAvailable(): bool
    {
        try { return Http::timeout(2)->get(env('OLLAMA_HOST', 'http://localhost:11434') . '/api/tags')->ok(); }
        catch (\Throwable) { return false; }
    }
    private function nul(string $t): array { return ['anyOf' => [['type' => $t], ['type' => 'null']]]; }
    private function parseItemsAi(string $text): array
    {
        $sys = "You extract line items from a shopkeeper's shorthand typing. Return the items and their quantities as JSON matching the schema. If a quantity is not stated, use 1. Set unit only if explicitly written. Never invent prices or items. Examples: \"2 coke, rice 3kg\" -> {\"items\":[{\"name\":\"coke\",\"quantity\":2},{\"name\":\"rice\",\"quantity\":3,\"unit\":\"kg\"}]}";
        $schema = ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'quantity' => ['type' => 'number'], 'unit' => $this->nul('string')], 'required' => ['name', 'quantity']]]], 'required' => ['items']];
        $out = json_decode($this->ollama($sys, $text, $schema), true);
        return array_map(fn ($i) => ['name' => $i['name'], 'quantity' => $i['quantity'] ?? 1, 'unit' => $i['unit'] ?? null], $out['items'] ?? []);
    }
    private function parseNewItemsAi(string $text): array
    {
        $sys = "You turn a shopkeeper's notes into product catalogue entries. Each item may include: name, category, unit, retail, wholesale, cost, stock, supplier. Fill only fields the text gives; use null otherwise. Prices/quantities are numbers only. Return JSON matching the schema (an 'items' array).";
        $schema = ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'category' => $this->nul('string'), 'unit' => $this->nul('string'), 'retail' => $this->nul('number'), 'wholesale' => $this->nul('number'), 'cost' => $this->nul('number'), 'stock' => $this->nul('number'), 'supplier' => $this->nul('string')], 'required' => ['name']]]], 'required' => ['items']];
        return json_decode($this->ollama($sys, $text, $schema), true)['items'] ?? [];
    }
    private function classifyAi(string $text): array
    {
        $sys = "You are the intent router for a small shop assistant. Classify the message into exactly one intent: item, price, stock, today, low_stock, best_sellers, record_sale, add_item, restock, help. A bare product name is item, never help/record_sale. Use record_sale only with a sell verb or explicit order quantity. Extract query = product name if mentioned; quantity = a number if stated, null otherwise. Return JSON matching the schema.";
        $schema = ['type' => 'object', 'properties' => ['intent' => ['type' => 'string', 'enum' => ['item', 'price', 'stock', 'today', 'low_stock', 'best_sellers', 'record_sale', 'add_item', 'restock', 'help']], 'query' => $this->nul('string'), 'quantity' => $this->nul('number')], 'required' => ['intent']];
        return json_decode($this->ollama($sys, $text, $schema), true);
    }
    private function chatAi(string $text): string
    {
        $sys = "You are a friendly, concise assistant inside a small shop's point-of-sale app. Reply in 1-2 short warm sentences. You cannot look up shop data here — if asked about a price, stock, sales, or to record a sale/add item/restock, tell them to say it plainly (e.g. 'price coke', 'sales today', 'sell 2 coke', 'restock rice 20'). Never invent numbers.";
        return trim($this->ollama($sys, $text));
    }

    public function parse(Request $r)
    {
        if (!$this->aiAvailable()) return $this->err(503, 'local AI not available (model not pulled or ollama down)');
        $lines = []; $unresolved = [];
        foreach ($this->parseItemsAi((string) $r->input('text')) as $p) {
            $m = Repo::findItems($p['name']);
            if (!$m) { $unresolved[] = $p['name']; continue; }
            $item = $m[0]; $price = Repo::currentPrice((int) $item->id);
            if (!$price) { $unresolved[] = "{$item->name} (no price)"; continue; }
            $qn = (float) $p['quantity'];
            $lines[] = ['item_id' => (int) $item->id, 'name' => $item->name, 'quantity' => $qn, 'unit_price' => (float) $price->price, 'line_total' => round($qn * (float) $price->price, 2)];
        }
        return response()->json(['lines' => $lines, 'unresolved' => $unresolved]);
    }
    public function parseCatalog(Request $r)
    {
        if (!$this->aiAvailable()) return $this->err(503, 'local AI not available (model not pulled or ollama down)');
        return response()->json(['items' => $this->parseNewItemsAi((string) $r->input('text'))]);
    }

    private const QUERY_NOISE = ['how much is', 'how much for', 'what is the price of', "what's the price of", 'the price of', 'price of', 'price for', 'do you have any', 'do you have', 'do we have', 'is there any', 'is there', 'tell me about', 'what about', 'how many', 'in stock', 'left of', 'price', 'stock'];
    private function stripQuery(string $text): string
    {
        $t = ' ' . rtrim(strtolower(trim($text)), "?.! ") . ' ';
        foreach (self::QUERY_NOISE as $p) $t = str_replace(" $p ", ' ', $t);
        return trim(implode(' ', array_filter(preg_split('/\s+/', $t))));
    }
    private function priceStr(int $itemId): string
    {
        $r = Repo::currentPrice($itemId, 'retail'); $w = Repo::currentPrice($itemId, 'wholesale');
        $parts = [];
        if ($r) $parts[] = 'retail ' . number_format((float) $r->price, 2);
        if ($w) $parts[] = 'wholesale ' . number_format((float) $w->price, 2);
        return $parts ? implode(', ', $parts) : 'no price set';
    }
    private function g($n): string { $n = (float) $n; return $n == (int) $n ? (string) (int) $n : (string) $n; }

    public function assistant(Request $r)
    {
        if (!$this->aiAvailable()) return $this->err(503, 'local AI not available (model not pulled or ollama down)');
        $text = trim((string) $r->input('text'));
        try { $intent = $this->classifyAi($text); }
        catch (\Throwable $e) { return $this->err(500, 'AI error: ' . $e->getMessage()); }
        $kind = $intent['intent'];
        $query = trim(($intent['query'] ?? '') ?: ($this->stripQuery($text) ?: $text));

        $itemReply = function (string $qq): string {
            $qq = trim($qq);
            if ($qq === '') return 'Which item? For example: “price coke”.';
            $matches = array_slice(Repo::findItems($qq), 0, 3);
            if (!$matches) return "“$qq” isn't in your catalogue yet. Add it in the Stock tab, or say “add item $qq …”.";
            $out = [];
            foreach ($matches as $m) $out[] = "{$m->name}: " . $this->priceStr((int) $m->id) . ' · ' . $this->g($m->quantity_on_hand) . " {$m->unit} in stock";
            return implode("\n", $out);
        };

        if (in_array($kind, ['item', 'price', 'stock'], true)) return response()->json(['reply' => $itemReply($query)]);
        if ($kind === 'today') { $sales = Repo::todaysSales(); $total = array_sum(array_map(fn ($s) => (float) $s->total, $sales)); return response()->json(['reply' => 'Today: ' . count($sales) . ' sale(s), total ' . number_format($total, 2) . '.']); }
        if ($kind === 'low_stock') { $thr = ($intent['quantity'] ?? null) ? (float) $intent['quantity'] : 5; $low = Repo::lowStock($thr); if (!$low) return response()->json(['reply' => 'Nothing is low on stock.']); return response()->json(['reply' => "Low stock:\n" . implode("\n", array_map(fn ($i) => "{$i->name}: " . $this->g($i->quantity_on_hand) . " {$i->unit}", $low))]); }
        if ($kind === 'best_sellers') { if ($intent['query'] ?? null) return response()->json(['reply' => $itemReply($intent['query'])]); $rows = Repo::bestSellers(30); if (!$rows) return response()->json(['reply' => 'No sales in the last 30 days.']); return response()->json(['reply' => "Best sellers (30d):\n" . implode("\n", array_map(fn ($x) => "{$x->name}: " . $this->g($x->qty) . ' sold', array_slice($rows, 0, 10)))]); }
        if ($kind === 'help') { $reply = ''; try { $reply = $this->chatAi($text); } catch (\Throwable) {} if ($reply === '') $reply = "I can tell you prices, stock, today's sales, low stock, and best sellers — and record a sale, add an item, or restock (you confirm first). Try: “price coke”, “sales today”, “sell 2 coke”, “restock rice 20”."; return response()->json(['reply' => $reply]); }

        if ($kind === 'record_sale') {
            $lines = []; $unresolved = [];
            foreach ($this->parseItemsAi($text) as $p) {
                $m = Repo::findItems($p['name']);
                if (!$m) { $unresolved[] = $p['name']; continue; }
                $item = $m[0]; $price = Repo::currentPrice((int) $item->id, 'retail');
                if (!$price) { $unresolved[] = "{$item->name} (no price)"; continue; }
                $qn = (float) $p['quantity'];
                $lines[] = ['item_id' => (int) $item->id, 'name' => $item->name, 'quantity' => $qn, 'unit_price' => (float) $price->price, 'line_total' => round($qn * (float) $price->price, 2)];
            }
            if (!$lines) { $miss = $unresolved ? ' (not found: ' . implode(', ', $unresolved) . ')' : ''; return response()->json(['reply' => "Couldn't match any items to sell$miss."]); }
            $total = round(array_sum(array_map(fn ($x) => $x['line_total'], $lines)), 2);
            $summary = implode(', ', array_map(fn ($x) => $this->g($x['quantity']) . ' ' . $x['name'], $lines));
            $note = $unresolved ? '  (not found: ' . implode(', ', $unresolved) . ')' : '';
            return response()->json(['reply' => "Ring up: $summary — total " . number_format($total, 2) . "?$note", 'action' => ['type' => 'sale', 'lines' => $lines, 'total' => $total]]);
        }
        if ($kind === 'add_item') {
            $drafts = $this->parseNewItemsAi($text);
            if (!$drafts) return response()->json(['reply' => "Couldn't read an item to add."]);
            return response()->json(['reply' => 'Add ' . count($drafts) . ' item(s): ' . implode(', ', array_map(fn ($d) => $d['name'], $drafts)) . '? Review & confirm.', 'action' => ['type' => 'items', 'items' => $drafts]]);
        }
        if ($kind === 'restock') {
            if (!($intent['query'] ?? null) || ($intent['quantity'] ?? null) === null) return response()->json(['reply' => 'Tell me the item and amount, e.g. “restock rice 20”.']);
            $m = Repo::findItems($intent['query']);
            if (!$m) return response()->json(['reply' => "No item matches “{$intent['query']}”."]);
            $item = $m[0];
            return response()->json(['reply' => "Restock {$item->name} by " . $this->g($intent['quantity']) . ' (now ' . $this->g($item->quantity_on_hand) . " {$item->unit})?", 'action' => ['type' => 'restock', 'item_id' => (int) $item->id, 'name' => $item->name, 'quantity' => $intent['quantity']]]);
        }
        return response()->json(['reply' => "Sorry, I didn't understand that."]);
    }

    // ---------- reports ----------
    public function today()
    {
        $sales = Repo::todaysSales();
        $total = array_sum(array_map(fn ($s) => (float) $s->total, $sales));
        return response()->json([
            'count' => count($sales), 'total' => $total,
            'sales' => array_map(fn ($s) => ['id' => (int) $s->id, 'total' => (float) $s->total, 'payment_method' => $s->payment_method, 'at' => $s->sold_at ? date('H:i', strtotime($s->sold_at)) : ''], $sales),
        ]);
    }
    public function report(Request $r)
    {
        $days = (int) $r->query('days', 7);
        return response()->json(array_map(fn ($x) => ['day' => (string) $x->day, 'sales' => (int) $x->sales, 'total' => (float) $x->total], Repo::salesSummary($days)));
    }
    public function best(Request $r)
    {
        $days = (int) $r->query('days', 30);
        return response()->json(array_map(fn ($x) => ['name' => $x->name, 'qty' => (float) $x->qty, 'revenue' => (float) $x->revenue], Repo::bestSellers($days)));
    }
    public function low(Request $r)
    {
        return response()->json($this->itemList(Repo::lowStock((float) $r->query('threshold', 5))));
    }
}
