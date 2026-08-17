<?php
// Repository: every DB read/write goes through here (mirrors repository.py).
namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class Repo
{
    // ---- Items ----
    public static function addItem(array $it): object
    {
        $row = DB::selectOne(
            'INSERT INTO items (name, brand, size, abv, vintage, style, origin, is_alcohol,
                                sku, barcode, category, unit, quantity_on_hand, active, supplier, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id',
            [$it['name'], $it['brand'] ?? null, $it['size'] ?? null, $it['abv'] ?? null, $it['vintage'] ?? null,
             $it['style'] ?? null, $it['origin'] ?? null, $it['is_alcohol'] ?? false, $it['sku'] ?? null,
             $it['barcode'] ?? null, $it['category'] ?? null, $it['unit'] ?? 'each',
             $it['quantity_on_hand'] ?? 0, $it['active'] ?? true, $it['supplier'] ?? null, $it['note'] ?? null]
        );
        return self::getItem($row->id);
    }

    public static function getItem(int $id): ?object
    {
        return DB::selectOne('SELECT * FROM items WHERE id = ?', [$id]);
    }

    public static function getItemByBarcode(string $code): ?object
    {
        return DB::selectOne('SELECT * FROM items WHERE barcode = ?', [trim($code)]);
    }

    public static function listItems(int $limit = 500): array
    {
        return DB::select('SELECT * FROM items WHERE active ORDER BY lower(category) NULLS LAST, lower(name) LIMIT ?', [$limit]);
    }

    public static function findItems(string $query, int $limit = 10, float $threshold = 0.3): array
    {
        $q = strtolower(trim($query));
        if ($q === '') return [];
        return DB::select(
            "SELECT i.*
             FROM items i
             LEFT JOIN LATERAL (
               SELECT MAX(similarity(lower(a.alias), :q)) AS best,
                      bool_or(lower(a.alias) LIKE '%' || :q || '%') AS sub
               FROM item_aliases a WHERE a.item_id = i.id
             ) al ON true
             WHERE i.active AND (
                lower(i.name) LIKE '%' || :q || '%'
                OR lower(coalesce(i.sku,'')) LIKE '%' || :q || '%'
                OR coalesce(i.barcode,'') LIKE '%' || :q || '%'
                OR similarity(lower(i.name), :q) >= :th
                OR COALESCE(al.sub,false)
                OR COALESCE(al.best,0) >= :th
             )
             ORDER BY GREATEST(similarity(lower(i.name), :q), COALESCE(al.best,0)) DESC, lower(i.name)
             LIMIT :lim",
            ['q' => $q, 'th' => $threshold, 'lim' => $limit]
        );
    }

    public static function updateItemMeta(int $id, string $name, ?string $brand, ?string $size, ?string $category): void
    {
        DB::update('UPDATE items SET name=?, brand=?, size=?, category=? WHERE id=?',
            [trim($name), self::nn($brand), self::nn($size), self::nn($category), $id]);
    }

    public static function updateItemInfo(int $id, $abv, $vintage, ?string $style, ?string $origin): void
    {
        DB::update('UPDATE items SET abv=?, vintage=?, style=?, origin=? WHERE id=?',
            [$abv, $vintage, self::nn($style), self::nn($origin), $id]);
    }

    public static function setBarcode(int $id, string $code): void
    {
        $code = trim($code);
        $clash = DB::selectOne('SELECT id FROM items WHERE barcode=? AND id<>?', [$code, $id]);
        if ($clash) throw new RuntimeException("barcode already used by item #{$clash->id}");
        DB::update('UPDATE items SET barcode=? WHERE id=?', [$code, $id]);
    }

    public static function renameCategory(?string $old, string $new): int
    {
        $new = trim($new);
        $rows = $old === null
            ? DB::select('UPDATE items SET category=? WHERE category IS NULL RETURNING id', [$new])
            : DB::select('UPDATE items SET category=? WHERE category=? RETURNING id', [$new, $old]);
        return count($rows);
    }

    public static function deleteItem(int $id): void
    {
        DB::delete('DELETE FROM items WHERE id=?', [$id]);
    }

    // ---- Images (bytea via hex in / base64 out to survive PDO) ----
    public static function addItemImage(int $itemId, string $data, string $contentType = 'image/jpeg'): int
    {
        return DB::transaction(function () use ($itemId, $data, $contentType) {
            $row = DB::selectOne(
                "INSERT INTO item_images (item_id, data, content_type, sort, updated_at)
                 VALUES (?, decode(?, 'hex'), ?, COALESCE((SELECT max(sort)+1 FROM item_images WHERE item_id = ?),0), now())
                 RETURNING id",
                [$itemId, bin2hex($data), $contentType, $itemId]
            );
            DB::update('UPDATE items SET has_image=true WHERE id=?', [$itemId]);
            return (int) $row->id;
        });
    }

    public static function listItemImages(int $itemId): array
    {
        return DB::select('SELECT id, content_type FROM item_images WHERE item_id=? ORDER BY sort, id', [$itemId]);
    }

    public static function getImage(int $imageId): ?array
    {
        $r = DB::selectOne("SELECT encode(data,'base64') AS b64, content_type FROM item_images WHERE id=?", [$imageId]);
        return $r ? ['data' => base64_decode($r->b64), 'content_type' => $r->content_type] : null;
    }

    public static function getItemImage(int $itemId): ?array
    {
        $r = DB::selectOne("SELECT encode(data,'base64') AS b64, content_type FROM item_images WHERE item_id=? ORDER BY sort, id LIMIT 1", [$itemId]);
        return $r ? ['data' => base64_decode($r->b64), 'content_type' => $r->content_type] : null;
    }

    public static function deleteImage(int $imageId): ?int
    {
        return DB::transaction(function () use ($imageId) {
            $row = DB::selectOne('DELETE FROM item_images WHERE id=? RETURNING item_id', [$imageId]);
            if (!$row) return null;
            $itemId = (int) $row->item_id;
            $still = DB::selectOne('SELECT 1 AS x FROM item_images WHERE item_id=? LIMIT 1', [$itemId]);
            if (!$still) DB::update('UPDATE items SET has_image=false WHERE id=?', [$itemId]);
            return $itemId;
        });
    }

    // ---- Options ----
    public static function addOption(int $itemId, string $name, float $price, float $amount = 1, string $currency = 'USD'): array
    {
        $row = DB::selectOne('INSERT INTO item_options (item_id,name,price,amount,currency) VALUES (?,?,?,?,?) RETURNING id',
            [$itemId, trim($name), $price, $amount, $currency]);
        return ['id' => (int) $row->id, 'item_id' => $itemId, 'name' => trim($name), 'price' => $price, 'amount' => $amount, 'currency' => $currency];
    }

    public static function getOptions(int $itemId): array
    {
        $rows = DB::select('SELECT id,name,price,amount,currency FROM item_options WHERE item_id=? ORDER BY id', [$itemId]);
        return array_map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'price' => (float) $r->price, 'amount' => (float) $r->amount, 'currency' => $r->currency], $rows);
    }

    public static function deleteOption(int $optionId): void
    {
        DB::delete('DELETE FROM item_options WHERE id=?', [$optionId]);
    }

    // ---- Prices ----
    public static function setPrice(int $itemId, float $price, string $kind = 'retail', string $currency = 'USD', $note = null): object
    {
        $row = DB::selectOne('INSERT INTO prices (item_id,price,kind,currency,note) VALUES (?,?,?,?,?) RETURNING id, effective_from',
            [$itemId, $price, $kind, $currency, $note]);
        return (object) ['item_id' => $itemId, 'price' => $price, 'kind' => $kind, 'currency' => $currency, 'effective_from' => $row->effective_from];
    }

    public static function currentPrice(int $itemId, string $kind = 'retail'): ?object
    {
        return DB::selectOne('SELECT item_id,kind,price,currency,effective_from FROM item_current_price WHERE item_id=? AND kind=?', [$itemId, $kind]);
    }

    /** @return array<int, array<string, object>> */
    public static function currentPricesFor(array $itemIds): array
    {
        $ids = array_values(array_filter($itemIds, fn ($i) => $i !== null));
        $out = [];
        if (!$ids) return $out;
        $rows = DB::table('item_current_price')->whereIn('item_id', $ids)
            ->select('item_id', 'kind', 'price', 'currency', 'effective_from')->get();
        foreach ($rows as $r) {
            $out[$r->item_id] ??= [];
            $out[$r->item_id][$r->kind] = $r;
        }
        return $out;
    }

    // ---- Stock ----
    public static function restock(int $itemId, float $quantity, $note = null): void
    {
        DB::transaction(function () use ($itemId, $quantity, $note) {
            DB::insert("INSERT INTO stock_movements (item_id,change,reason,ref) VALUES (?,?,'restock',?)", [$itemId, $quantity, $note]);
            DB::update('UPDATE items SET quantity_on_hand = quantity_on_hand + ? WHERE id=?', [$quantity, $itemId]);
        });
    }

    // ---- Sales ----
    public static function recordSale(string $paymentMethod, string $currency, array $lines): array
    {
        if (!$lines) throw new RuntimeException('cannot record a sale with no lines');
        $total = 0.0;
        foreach ($lines as $l) $total += $l['line_total'] ?? ($l['quantity'] * $l['unit_price']);
        return DB::transaction(function () use ($paymentMethod, $currency, $lines, $total) {
            $head = DB::selectOne('INSERT INTO sales (total,currency,payment_method,note) VALUES (?,?,?,?) RETURNING id, sold_at',
                [$total, $currency, $paymentMethod, null]);
            $id = (int) $head->id;
            foreach ($lines as $l) {
                $lt = $l['line_total'] ?? ($l['quantity'] * $l['unit_price']);
                DB::insert('INSERT INTO sale_lines (sale_id,item_id,description,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?)',
                    [$id, $l['item_id'], $l['description'], $l['quantity'], $l['unit_price'], $lt]);
                if ($l['item_id'] !== null) {
                    DB::insert("INSERT INTO stock_movements (item_id,change,reason,ref) VALUES (?,?,'sale',?)", [$l['item_id'], -$l['quantity'], "sale:$id"]);
                    DB::update('UPDATE items SET quantity_on_hand = quantity_on_hand - ? WHERE id=?', [$l['quantity'], $l['item_id']]);
                }
            }
            return ['id' => $id, 'total' => $total, 'currency' => $currency, 'lines' => count($lines)];
        });
    }

    public static function todaysSales(): array
    {
        return DB::select('SELECT id, sold_at, total, currency, payment_method, note, voided_at FROM sales WHERE sold_at::date = CURRENT_DATE AND voided_at IS NULL ORDER BY sold_at');
    }

    public static function voidSale(int $saleId): array
    {
        return DB::transaction(function () use ($saleId) {
            $head = DB::selectOne('SELECT id, voided_at FROM sales WHERE id=? FOR UPDATE', [$saleId]);
            if (!$head) throw new RuntimeException("no sale #$saleId");
            if ($head->voided_at !== null) throw new RuntimeException("sale #$saleId is already voided");
            foreach (DB::select('SELECT item_id, quantity FROM sale_lines WHERE sale_id=?', [$saleId]) as $l) {
                if ($l->item_id !== null) {
                    DB::insert("INSERT INTO stock_movements (item_id,change,reason,ref) VALUES (?,?,'void',?)", [$l->item_id, $l->quantity, "void:$saleId"]);
                    DB::update('UPDATE items SET quantity_on_hand = quantity_on_hand + ? WHERE id=?', [$l->quantity, $l->item_id]);
                }
            }
            DB::update('UPDATE sales SET voided_at=now() WHERE id=?', [$saleId]);
            $t = DB::selectOne('SELECT total FROM sales WHERE id=?', [$saleId]);
            return ['id' => $saleId, 'total' => (float) $t->total];
        });
    }

    // ---- Reports ----
    public static function salesSummary(int $days = 7): array
    {
        return DB::select("SELECT sold_at::date AS day, count(*) AS sales, coalesce(sum(total),0) AS total
            FROM sales WHERE voided_at IS NULL AND sold_at::date >= CURRENT_DATE - (?::int - 1)
            GROUP BY day ORDER BY day DESC", [$days]);
    }

    public static function bestSellers(int $days = 30, int $limit = 10): array
    {
        return DB::select("SELECT coalesce(i.name, sl.description) AS name, sum(sl.quantity) AS qty, sum(sl.line_total) AS revenue
            FROM sale_lines sl JOIN sales s ON s.id=sl.sale_id LEFT JOIN items i ON i.id=sl.item_id
            WHERE s.voided_at IS NULL AND s.sold_at::date >= CURRENT_DATE - (?::int - 1)
            GROUP BY coalesce(i.name, sl.description) ORDER BY qty DESC LIMIT ?", [$days, $limit]);
    }

    public static function frequentItems(int $days = 30, int $limit = 12): array
    {
        return DB::select("SELECT i.* FROM items i
            LEFT JOIN sale_lines sl ON sl.item_id = i.id
            LEFT JOIN sales s ON s.id = sl.sale_id AND s.voided_at IS NULL AND s.sold_at::date >= CURRENT_DATE - (?::int - 1)
            WHERE i.active GROUP BY i.id
            ORDER BY coalesce(sum(sl.quantity),0) DESC, lower(i.name) LIMIT ?", [$days, $limit]);
    }

    public static function lowStock(float $threshold = 5, int $limit = 50): array
    {
        return DB::select('SELECT * FROM items WHERE active AND quantity_on_hand <= ? ORDER BY quantity_on_hand ASC, lower(name) LIMIT ?', [$threshold, $limit]);
    }

    private static function nn(?string $v): ?string
    {
        $v = trim($v ?? '');
        return $v === '' ? null : $v;
    }
}
