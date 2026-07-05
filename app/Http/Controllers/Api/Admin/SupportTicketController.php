<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class SupportTicketController extends Controller
{
    /**
     * GET: List all support tickets
     */
    public function index(Request $request)
    {
        try {
            $query = SupportTicket::query();

            // Search
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('ticket_id', 'like', "%{$search}%")
                      ->orWhere('user_name', 'like', "%{$search}%")
                      ->orWhere('question', 'like', "%{$search}%")
                      ->orWhere('status', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('status') && !empty($request->status)) {
                $query->where('status', $request->status);
            }

            $tickets = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'status' => 'success',
                'data' => $tickets
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve support tickets.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET: Show a specific support ticket
     */
    public function show($id)
    {
        try {
            $ticket = SupportTicket::with('user')->find($id);

            if (!$ticket) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Support ticket not found.'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $ticket
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve support ticket details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST/PUT/PATCH: Submit a reply to a support ticket
     */
    public function reply(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'admin_reply' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $ticket = SupportTicket::find($id);

            if (!$ticket) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Support ticket not found.'
                ], 404);
            }

            $ticket->admin_reply = $request->admin_reply;
            if ($ticket->status === 'Pending') {
                $ticket->status = 'Replied';
            }
            $ticket->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Reply submitted successfully.',
                'data' => $ticket
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit reply.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PATCH: Update support ticket status explicitly (e.g., Solved, Not Solved)
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:Pending,Replied,Solved,Not Solved',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $ticket = SupportTicket::find($id);

            if (!$ticket) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Support ticket not found.'
                ], 404);
            }

            $ticket->status = $request->status;
            $ticket->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Ticket status updated successfully.',
                'data' => $ticket
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update ticket status.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE: Delete a support ticket
     */
    public function destroy($id)
    {
        try {
            $ticket = SupportTicket::find($id);

            if (!$ticket) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Support ticket not found.'
                ], 404);
            }

            $ticket->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Support ticket deleted successfully.'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete support ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
