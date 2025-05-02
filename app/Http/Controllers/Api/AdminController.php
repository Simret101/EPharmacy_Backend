<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\PharmacistResource;
use App\Models\Pharmacist;
use App\Models\User;
use App\Notifications\PharmacistVerificationStatus;
use App\Notifications\PharmacistStatusUpdated;
use App\Notifications\AdminPharmacistRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    
   

    
    public function viewLicenseImage($id)
    {
        $pharmacist = Pharmacist::find($id);

        if (!$pharmacist) {
            return response()->json([
                'message' => 'Pharmacist not found'
            ], 404);
        }

        if (!$pharmacist->license_image) {
            return response()->json([
                'message' => 'No license image found for this pharmacist'
            ], 404);
        }

        
        return response()->json([
            'message' => 'License image fetched successfully',
            'data' => asset('app/public/' .  $pharmacist->license_image)
        ]);
    }


    public function approvePharmacist(Request $request, $id)
    {
        try {
            $pharmacist = Pharmacist::find($id);

            if (!$pharmacist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pharmacist not found'
                ], 404);
            }

            DB::beginTransaction();

            try {
                // Update pharmacist status
                $pharmacist->status = 'approved';
                $pharmacist->status_reason = 'Documents verified';
                $pharmacist->status_updated_at = now();
                $pharmacist->save();

                // Update user status
                $user = User::find($pharmacist->user_id);
                if ($user) {
                    $user->status = 'approved';
                    $user->save();

                    // Send notification
                    $user->notify(new PharmacistStatusUpdated('approved', 'Documents verified'));
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Pharmacist approved successfully',
                    'data' => new PharmacistResource($pharmacist)
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error('Error approving pharmacist: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error approving pharmacist',
                    'error' => $e->getMessage()
                ], 500);
            }

        } catch (\Exception $e) {
            \Log::error('Error in approvePharmacist: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function rejectPharmacist(Request $request, $id)
    {
        try {
            $pharmacist = Pharmacist::find($id);

            if (!$pharmacist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pharmacist not found'
                ], 404);
            }

            DB::beginTransaction();

            try {
                // Update pharmacist status
                $pharmacist->status = 'rejected';
                $pharmacist->status_reason = 'Documents not verified';
                $pharmacist->status_updated_at = now();
                $pharmacist->save();

                // Update user status
                $user = User::find($pharmacist->user_id);
                if ($user) {
                    $user->status = 'rejected';
                    $user->save();

                    // Send notification
                    $user->notify(new PharmacistStatusUpdated('rejected', 'Documents not verified'));
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Pharmacist rejected successfully',
                    'data' => new PharmacistResource($pharmacist)
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error('Error rejecting pharmacist: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error rejecting pharmacist',
                    'error' => $e->getMessage()
                ], 500);
            }

        } catch (\Exception $e) {
            \Log::error('Error in rejectPharmacist: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePharmacistStatus(Request $request, $id)
    {
        try {
            // Get status from either POST data or query parameters
            $status = $request->input('action') === 'approve' ? 'approved' : 
                     ($request->input('action') === 'reject' ? 'rejected' : 
                     $request->query('status'));

            $reason = $request->input('reason') ?? $request->query('reason') ?? 
                     ($status === 'approved' ? 'Documents verified' : 'Documents not verified');

            if (!$status || !in_array($status, ['approved', 'rejected'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid status provided'
                ], 400);
            }

            $pharmacist = User::where('is_role', 2)->findOrFail($id);

            DB::beginTransaction();

            try {
                // Update the pharmacist's status
                $pharmacist->status = $status;
                $pharmacist->status_reason = $reason;
                $pharmacist->status_updated_at = now();
                $pharmacist->save();

                // Queue the notification instead of sending it immediately
                $pharmacist->notify((new PharmacistStatusUpdated($status, $reason))->delay(now()->addSeconds(5)));

                DB::commit();

                // If it's a GET request (from email link), return a simple success page
                if ($request->method() === 'GET') {
                    $message = $status === 'approved' ? 
                        'Pharmacist has been approved successfully.' : 
                        'Pharmacist has been rejected.';
                    
                    return response()->view('status-update-success', [
                        'message' => $message,
                        'status' => $status
                    ]);
                }

                // For API requests, return JSON
                return response()->json([
                    'success' => true,
                    'message' => 'Pharmacist status updated successfully',
                    'data' => $pharmacist->fresh() // Get fresh data from database
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error('Error updating pharmacist status: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error updating pharmacist status',
                    'error' => $e->getMessage()
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('Error in updatePharmacistStatus: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifyEmail($token)
    {
        try {
            $verification = DB::table('email_verification_tokens')
                ->where('token', $token)
                ->where('expired_at', '>', now())
                ->first();

            if (!$verification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired verification token'
                ], 400);
            }

            $user = User::where('email', $verification->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            DB::beginTransaction();

            try {
                // Update user's email verification status
                $user->email_verified_at = now();
                $user->save();

                // Delete the used token
                DB::table('email_verification_tokens')
                    ->where('token', $token)
                    ->delete();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Email verified successfully. You can now log in to your account.',
                    'data' => [
                        'email' => $user->email,
                        'verified_at' => $user->email_verified_at
                    ]
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error('Error verifying email: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error verifying email',
                    'error' => $e->getMessage()
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('Error in verifyEmail: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function handleEmailAction(Request $request, $id)
    {
        try {
            // Get status from action parameter
            $action = $request->query('action');
            $status = $action === 'approve' ? 'approved' : 
                     ($action === 'reject' ? 'rejected' : null);

            if (!$status || !in_array($status, ['approved', 'rejected'])) {
                return response('Invalid action provided', 400);
            }

            $pharmacist = Pharmacist::find($id);
            if (!$pharmacist) {
                return response('Pharmacist not found', 404);
            }

            DB::beginTransaction();

            try {
                // Update pharmacist status
                $pharmacist->status = $status;
                $pharmacist->status_reason = $status === 'approved' ? 'Documents verified' : 'Documents not verified';
                $pharmacist->status_updated_at = now();
                $pharmacist->save();

                // Update user status (only status field)
                $user = User::find($pharmacist->user_id);
                if ($user) {
                    $user->update(['status' => $status]);
                    $user->notify(new PharmacistStatusUpdated($status, $pharmacist->status_reason));
                }

                DB::commit();

                // Return simple success message
                return response('Pharmacist has been ' . $status . ' successfully.');

            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error('Error updating pharmacist status: ' . $e->getMessage());
                return response('Error updating pharmacist status', 500);
            }
        } catch (\Exception $e) {
            \Log::error('Error in handleEmailAction: ' . $e->getMessage());
            return response('An error occurred', 500);
        }
    }

    public function getPendingPharmacists()
    {
        // Check if the authenticated user is an admin
        if (Auth::user()->is_role !== 0) {
            return response()->json([
                'message' => 'Unauthorized. Only admins can perform this action.'
            ], 403);
        }

        $pendingPharmacists = User::where('is_role', 2)
            ->where('status', 'pending')
            ->paginate(10);

        return response()->json([
            'pharmacists' => $pendingPharmacists
        ]);
    }

    public function getAllPharmacists(Request $request)
    {
        // Check if user is admin
        if (Auth::user()->is_role !== 0) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized. Only admins can access this endpoint.'
            ], 403);
        }

        try {
            $query = User::where('is_role', 2); // Get only pharmacists

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Search by name, email, or pharmacy name
            if ($request->has('search')) {
                $searchTerm = $request->search;
                $query->where(function($q) use ($searchTerm) {
                    $q->where('name', 'like', "%{$searchTerm}%")
                      ->orWhere('email', 'like', "%{$searchTerm}%")
                      ->orWhere('pharmacy_name', 'like', "%{$searchTerm}%");
                });
            }

            // Sort by created_at by default, but allow custom sorting
            $sortBy = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';
            $query->orderBy($sortBy, $sortOrder);

            // Paginate results
            $perPage = $request->per_page ?? 10;
            $pharmacists = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $pharmacists
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Error fetching pharmacists',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
