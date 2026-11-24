<?php

namespace Webkul\Admin\Http\Controllers\User;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;

class AccountController extends Controller
{
    /**
     * Show the form for creating a new resource.
     *
     * @return View
     */
    public function edit()
    {
        $user = auth()->guard('user')->user();

        return view('admin::user.account.edit', compact('user'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update()
    {
        $user = auth()->guard('user')->user();

        $this->validate(request(), [
            'name' => 'required',
            'email' => 'email|unique:users,email,'.$user->id,
            'password' => 'nullable|min:6|confirmed',
            'current_password' => 'required|min:6',
            'image.*' => 'nullable|mimes:bmp,jpeg,jpg,png,webp',
        ]);

        $data = request()->only([
            'name',
            'email',
            'password',
            'password_confirmation',
            'current_password',
            'image',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            session()->flash('warning', trans('admin::app.account.edit.invalid-password'));

            return redirect()->back();
        }

        if (isset($data['role_id']) || isset($data['view_permission'])) {
            session()->flash('warning', trans('admin::app.user.account.permission-denied'));

            return redirect()->back();
        }

        $isPasswordChanged = false;

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $isPasswordChanged = true;

            $data['password'] = bcrypt($data['password']);
        }

        $imageFile = request()->file('image');

        if (is_array($imageFile)) {
            $imageFile = reset($imageFile);
        }

        if ($imageFile instanceof \Illuminate\Http\UploadedFile) {
            if ($user->image) {
                Storage::delete($user->image);
            }

            $data['image'] = $imageFile->store('admins/'.$user->id);
        } else {
            // No new file uploaded -- keep the existing avatar untouched.
            $data['image'] = $user->image;
        }

        $user->update($data);

        if ($isPasswordChanged) {
            Event::dispatch('user.account.update-password', $user);
        }

        session()->flash('success', trans('admin::app.account.edit.update-success'));

        return back();
    }
}
