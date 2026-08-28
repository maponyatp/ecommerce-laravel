<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewRequest;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    /**
     * Handles the request to store a new review.
     *
     * @param  ReviewRequest  $request  The request object containing review details.
     * @return JsonResponse A JSON response indicating success and the saved review.
     */
    public function store(ReviewRequest $request)
    {
        $validatedData = $request->validated();

        $review = new Review;
        $review->user_id = Auth::id();
        $review->product_id = $validatedData['product_id'];
        $review->rating = $validatedData['rating'];
        $review->review = $validatedData['review'];
        $review->approved = false; // Reviews are not approved by default

        // Check if the user has purchased the product
        // $hasOrderedProduct = Order::where('user_id', Auth::id())
        //     ->whereHas('orderItems', function ($query) use ($request) {
        //         $query->where('product_id', $request->product_id);
        //     })->exists();

        // $review->is_verified_purchase = $hasOrderedProduct;

        $review->save();

        return response()->json(['message' => 'Review submitted successfully', 'review' => $review], 201);
    }

    public function approve($id)
    {
        $review = Review::find($id);
        if (! $review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $review->approved = true;
        $review->save();

        return response()->json(['message' => 'Review approved successfully']);
    }

    public function show($productId)
    {
        $reviews = Review::where('product_id', $productId)
            ->where('approved', true)
            ->with('user:id,name')
            ->get();

        return response()->json($reviews);
    }

    public function vote(Request $request, $id)
    {
        if (! in_array($request->input('vote'), ['helpful', 'unhelpful'], true)) {
            return response()->json(['message' => 'Invalid vote type'], 400);
        }

        return DB::transaction(function () use ($request, $id) {
            $review = Review::where('approved', true)->lockForUpdate()->findOrFail($id);
            $previous = DB::table('review_votes')->where('review_id', $review->id)->where('user_id', $request->user()->id)->first();
            $vote = $request->input('vote');
            if ($previous?->vote !== $vote) {
                if ($previous) {
                    $column = $previous->vote.'_votes';
                    $review->{$column} = max(0, $review->{$column} - 1);
                }
                $column = $vote.'_votes';
                $review->{$column}++;
                $review->save();
                if ($previous) {
                    DB::table('review_votes')->where('id', $previous->id)->update(['vote' => $vote, 'updated_at' => now()]);
                } else {
                    DB::table('review_votes')->insert(['review_id' => $review->id, 'user_id' => $request->user()->id,
                        'vote' => $vote, 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            return response()->json(['message' => 'Vote recorded successfully']);
        }, 3);
    }
}
