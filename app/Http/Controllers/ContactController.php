<?php

namespace App\Http\Controllers;

use App\Mail\ContactEnquiryMail;
use App\Settings\GeneralSettings;
use App\Support\StoreBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(Request $request, GeneralSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $recipient = app(StoreBranding::class)->current()['email'];
        if (! $recipient) {
            return back()->withInput()->with('error', 'The store contact email is not configured yet. Please try again later.');
        }
        Mail::to($recipient)->send(new ContactEnquiryMail($data));

        return back()->with('success', 'Thank you. Your enquiry has been sent and our team will reply soon.');
    }
}
