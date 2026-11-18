<?php

namespace App\Http\Controllers;

use App\Models\{Campaign, Customer, Product, Sale, Supplier};
use Illuminate\Http\Request;

class CrudController extends Controller
{
    private const MAP = [
        'customers' => Customer::class,
        'products' => Product::class,
        'sales' => Sale::class,
        'campaigns' => Campaign::class,
        'suppliers' => Supplier::class,
    ];

    private function rules(string $res): array
    {
        return match ($res) {
            'customers' => ['name' => 'required|string|max:255', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:30',
                'age' => 'required|integer|min:18|max:100', 'city' => 'required|string', 'joined_at' => 'required|date'],
            'products' => ['name' => 'required|string', 'category' => 'required|string', 'brand' => 'required|string',
                'supplier_id' => 'required|exists:suppliers,id', 'price' => 'required|numeric|min:0', 'cost' => 'required|numeric|min:0',
                'stock' => 'required|integer|min:0', 'reorder_level' => 'required|integer|min:0'],
            'sales' => ['customer_id' => 'required|exists:customers,id', 'product_id' => 'required|exists:products,id',
                'campaign_id' => 'nullable|exists:campaigns,id', 'quantity' => 'required|integer|min:1',
                'sales_channel' => 'required|in:Store,Online', 'sold_at' => 'required|date'],
            'campaigns' => ['name' => 'required|string', 'channel' => 'required|string', 'spend' => 'required|numeric|min:0',
                'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date'],
            'suppliers' => ['name' => 'required|string', 'contact_person' => 'required|string', 'email' => 'required|email',
                'city' => 'required|string', 'lead_time_days' => 'required|integer|min:1'],
        };
    }

    private function model(string $res): string
    {
        return self::MAP[$res] ?? abort(404);
    }

    private function query(string $res)
    {
        $m = $this->model($res);
        $q = $m::query()->orderByDesc('id');

        return match ($res) {
            'sales' => $q->with(['customer:id,name', 'product:id,name', 'campaign:id,name']),
            'products' => $q->with('supplier:id,name'),
            default => $q,
        };
    }

    public function index(string $res)
    {
        return $this->query($res)->get();
    }

    public function store(Request $r, string $res)
    {
        $data = $r->validate($this->rules($res));
        if ($res === 'sales') {
            $product = Product::findOrFail($data['product_id']);
            $data['total'] = $product->price * $data['quantity'];
            $product->decrement('stock', min($product->stock, $data['quantity']));
        }
        $row = $this->model($res)::create($data);

        return response()->json($this->query($res)->find($row->id), 201);
    }

    public function update(Request $r, string $res, int $id)
    {
        $data = $r->validate($this->rules($res));
        $row = $this->model($res)::findOrFail($id);
        if ($res === 'sales') {
            $data['total'] = Product::findOrFail($data['product_id'])->price * $data['quantity'];
        }
        $row->update($data);

        return $this->query($res)->find($row->id);
    }

    public function destroy(string $res, int $id)
    {
        $this->model($res)::findOrFail($id)->delete();

        return response()->noContent();
    }
}
