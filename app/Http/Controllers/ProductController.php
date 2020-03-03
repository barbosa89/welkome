<?php

namespace App\Http\Controllers;

use App\Exports\ProductReport;
use App\Exports\ProductsReport;
use App\Helpers\Chart;
use App\User;
use App\Helpers\Id;
use App\Helpers\Fields;
use App\Helpers\Input;
use App\Helpers\Random;
use Illuminate\Http\Request;
use Vinkla\Hashids\Facades\Hashids;
use App\Http\Requests\{DateRangeQuery, ReportQuery, StoreProduct, UpdateProduct};
use App\Welkome\Company;
use App\Welkome\Hotel;
use App\Welkome\Product;
use App\Welkome\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $hotels = Hotel::whereHas('owner', function (Builder $query) {
            $query->where('id', Id::parent());
        })->with([
            'products' => function ($query) {
                $query->select(Fields::get('products'));
            }
        ])->get(Fields::get('hotels'));

        if ($hotels->isEmpty()) {
            flash(trans('hotels.no.registered'))->info();

            return redirect()->route('hotels.index');
        }

        $hotels = $this->encodeIds($hotels);

        return view('app.products.index', compact('hotels'));
    }

    /**
     * Encode all ID's from collection
     *
     * @param  \Illuminate\Support\Collection
     * @return \Illuminate\Support\Collection
     */
    public function encodeIds(Collection $hotels)
    {
        $hotels = $hotels->map(function ($hotel) {
            $hotel->user_id = Hashids::encode($hotel->user_id);
            $hotel->main_hotel = empty($hotel->main_hotel) ? null : Hashids::encode($hotel->main_hotel);
            $hotel->products = $hotel->products->map(function ($product) {
                $product->hotel_id = Hashids::encode($product->hotel_id);
                $product->user_id = Hashids::encode($product->user_id);

                return $product;
            });

            return $hotel;
        });

        return $hotels;
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $hotels = Hotel::whereHas('owner', function (Builder $query) {
            $query->where('id', Id::parent());
        })->whereStatus(true)
            ->get(Fields::get('hotels'));

        if ($hotels->isEmpty()) {
            flash(trans('hotels.no.registered'))->info();

            return redirect()->route('hotels.index');
        }

        $companies = Company::where('user_id', Id::parent())
            ->where('is_supplier', true)
            ->get(Fields::get('companies'));

        return view('app.products.create', compact('hotels', 'companies'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreProduct $request)
    {
        $product = new Product();
        $product->description = $request->description;
        $product->brand = $request->brand;
        $product->reference = $request->reference;
        $product->price = (float) $request->price;
        $product->quantity = $request->quantity;
        $product->user()->associate(Id::parent());
        $product->hotel()->associate(Id::get($request->hotel));

        if ($product->save()) {
            // Voucher creation
            $voucher = new Voucher();
            $voucher->number = Random::consecutive();
            $voucher->open = false;
            $voucher->payment_status = true;
            $voucher->type = 'entry';
            $voucher->value = $product->price * $product->quantity;
            $voucher->subvalue = $product->price * $product->quantity;
            $voucher->made_by = auth()->user()->name;
            $voucher->comments = $request->comments;
            $voucher->hotel()->associate(Id::get($request->hotel));
            $voucher->user()->associate(Id::parent());

            if (!empty($request->company)) {
                $voucher->company()->associate(Id::get($request->company));
            }

            if ($voucher->save()) {
                // Attach product
                $voucher->products()->attach(
                    $product->id,
                    [
                        'quantity' => $product->quantity,
                        'value' => $product->price * $product->quantity,
                        'created_at' => now()
                    ]
                );
            }

            flash(trans('common.createdSuccessfully'))->success();

            return redirect()->route('products.show', [
                'id' => Hashids::encode($product->id)
            ]);
        }

        flash(trans('common.error'))->error();

        return redirect()->route('products.index');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $product = User::find(Id::parent(), ['id'])
            ->products()
            ->where('id', Id::get($id))
            ->first(Fields::get('products'));

        if (empty($product)) {
            abort(404);
        }

        $product->load([
            'hotel' => function ($query) {
                $query->select(Fields::get('hotels'));
            },
            'vouchers' => function ($query) {
                $query->select(Fields::parsed('vouchers'))
                    ->orderBy('vouchers.created_at', 'DESC')
                    ->whereYear('vouchers.created_at', \date('Y'))
                    ->withPivot('quantity');
            }
        ]);

        $data = Chart::data($product->vouchers);

        return view('app.products.show', compact('product', 'data'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $product = User::find(Id::parent(), ['id'])
            ->products()
            ->where('id', Id::get($id))
            ->with([
                'hotel' => function ($query) {
                    $query->select(Fields::get('hotels'));
                }
            ])->first(Fields::get('products'));

        if (empty($product)) {
            abort(404);
        }

        return view('app.products.edit', compact('product'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateProduct $request, $id)
    {
        $product = User::find(Id::parent(), ['id'])
            ->products()
            ->where('id', Id::get($id))
            ->first(Fields::get('products'));

        if (empty($product)) {
            abort(404);
        }

        $product->description = $request->description;
        $product->brand = $request->brand;
        $product->reference = $request->reference;
        $product->price = (float) $request->price;

        if ($product->update()) {
            flash(trans('common.updatedSuccessfully'))->success();

            return redirect()->route('products.show', [
                'id' => Hashids::encode($product->id)
            ]);
        }

        flash(trans('common.error'))->error();

        return back();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $product = User::find(Id::parent(), ['id'])->products()
            ->where('id', Id::get($id))
            ->first(Fields::get('products'));

        if (empty($product)) {
            abort(404);
        }

        $product->load([
            'vouchers' => function ($query) {
                $query->select('vouchers.id');
            },
        ]);

        if ($product->vouchers->count() > 0) {
            $product->status = 0;

            if ($product->update()) {
                flash(trans('products.wasDisabled'))->success();

                return redirect()->route('products.index');
            }
        } else {
            if ($product->delete()) {
                flash(trans('common.deletedSuccessfully'))->success();

                return redirect()->route('products.index');
            }
        }

        flash(trans('common.error'))->error();

        return redirect()->route('products.index');
    }

    /**
     * Return price of resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function total(Request $request)
    {
        if ($request->ajax()) {
            $product = Product::find(Id::get($request->element), ['id', 'price']);

            if (empty($product)) {
                return response()->json(['value' => null]);
            } else {
                $value = (int) $request->quantity * $product->price;
                $value = number_format($value, 2, ',', '.');

                return response()->json(['value' => $value]);
            }
        }

        abort(404);
    }

    /**
     * Toggle status for the specified resource from storage.
     *
     * @param  string   $id
     * @return \Illuminate\Http\Response
     */
    public function toggle($id)
    {
        $product = User::find(Id::parent(), ['id'])
            ->products()
            ->where('id', Id::get($id))
            ->first(Fields::get('products'));

        if (empty($product)) {
            return abort(404);
        }

        $product->status = !$product->status;

        if ($product->save()) {
            flash(trans('common.updatedSuccessfully'))->success();

            return back();
        }

        flash(trans('common.error'))->error();

        return back();
    }

    /**
     * Return a rooms list by hotel ID.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function search(Request $request)
    {
        if ($request->ajax()) {
            $query = Input::clean($request->get('query', null));

            $products = Product::where('hotel_id', Id::get($request->hotel))
                ->where('user_id', Id::parent())
                ->whereLike(['description', 'brand', 'reference'], $query)
                ->get(Fields::get('products'));

            $products = $products->map(function ($product) {
                $product->hotel_id = Hashids::encode($product->hotel_id);
                $product->user_id = Hashids::encode($product->user_id);

                return $product;
            });

            return response()->json([
                'results' => $products->toJson()
            ]);
        }

        abort(404);
    }

    /**
     * Display the product report form to query between dates.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function showProductReportForm($id)
    {
        $product = User::find(Id::parent(), ['id'])->products()
            ->where('id', Id::get($id))
            ->first(Fields::get('products'));

        if (empty($product)) {
            abort(404);
        }

        $product->load([
            'hotel' => function ($query)
            {
                $query->select(['id', 'business_name']);
            }
        ]);

        return view('app.products.product-report', compact('product'));
    }

    /**
     * Export Product report in an excel document.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function exportProductReport(DateRangeQuery $request, $id)
    {
        $product = User::find(Id::parent(), ['id'])->products()
            ->where('id', Id::get($id))
            ->first(Fields::get('products'));

        if (empty($product)) {
            abort(404);
        }

        $product->load([
            'hotel' => function ($query)
            {
                $query->select(['id', 'business_name']);
            },
            'vouchers' => function ($query) use ($request)
            {
                $query->select(Fields::parsed('vouchers'))
                    ->whereBetween('vouchers.created_at', [$request->start, $request->end])
                    ->orderBy('vouchers.created_at', 'DESC')
                    ->withPivot('quantity', 'value');
            },
            'vouchers.company' => function ($query) use ($request)
            {
                $query->select(Fields::parsed('companies'));
            },
        ]);

        if ($product->vouchers->isEmpty()) {
            flash(trans('common.without.results'))->info();

            return redirect()->route('products.product.report', ['id' => Hashids::encode($product->id)]);
        }

        return Excel::download(new ProductReport($product), trans('products.product') . '.xlsx');
    }

    /**
     * Display the report form to query between dates and hotels.
     *
     * @return \Illuminate\Http\Response
     */
    public function showReportForm()
    {
        $hotels = Hotel::where('user_id', Id::parent())
            ->get(Fields::get('hotels'));

        if($hotels->isEmpty()) {
            flash(trans('hotels.no.registered'))->info();

            return redirect()->route('products.index');
        }

        return view('app.products.report', compact('hotels'));
    }

    /**
     * Export the products report in an excel document.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function exportReport(ReportQuery $request)
    {
        $query = Hotel::query();
        $query->where('user_id', Id::parent());

        if (!empty($request->hotel)) {
            $query->where('id', Id::get($request->hotel));
        }

        $query->with([
            'products' => function($query) {
                $query->select(Fields::get('products'));
            },
            'products.vouchers' => function ($query) use ($request)
            {
                $query->select(Fields::parsed('vouchers'))
                    ->whereBetween('vouchers.created_at', [$request->start, $request->end])
                    ->orderBy('vouchers.created_at', 'DESC')
                    ->withPivot('quantity', 'value');
            },
            'products.vouchers.company' => function ($query) use ($request)
            {
                $query->select(Fields::parsed('companies'));
            }
        ]);

        $hotels = $query->get(Fields::get('hotels'));

        if($hotels->isEmpty()) {
            flash(trans('hotels.no.registered'))->info();

            return back();
        }

        return Excel::download(new ProductsReport($hotels), trans('products.title') . '.xlsx');
    }
}
