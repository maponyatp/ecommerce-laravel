<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteSettingController extends Controller
{
    public function index()
    {
        $settings = SiteSetting::all();

        return response()->json($settings);
    }

    public function edit($id)
    {
        $setting = SiteSetting::findOrFail($id);

        return response()->json($setting);
    }

    public function update(Request $request, $id)
    {
        $setting = SiteSetting::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique('site_settings', 'name')->ignore($setting->id)],
            'value' => 'required|string',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $setting->update($validator->validated());
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['name' => 'That setting name is already in use.']);
        }

        return response()->json($setting);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:site_settings',
            'value' => 'required|string',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $setting = SiteSetting::create($request->all());

        return response()->json($setting, 201);
    }
}
