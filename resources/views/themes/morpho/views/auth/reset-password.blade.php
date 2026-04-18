@extends('morpho::layouts.default')

@section('content')
<div class="auth-container">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-sm-10 col-md-8 col-lg-5 col-xl-4">
                <div class="auth-card">
                    <div class="auth-header">
                        <div class="auth-logo mb-4">
                            <h1 class="brand-name">{{ config('app.name', 'Dujiaoka') }}</h1>
                            <p class="brand-tagline">设置新密码</p>
                        </div>
                    </div>
                    <div class="auth-body">
                        @if ($errors->any())
                            <div class="alert alert-danger">
                                @foreach ($errors->all() as $error)
                                    <div>{{ $error }}</div>
                                @endforeach
                            </div>
                        @endif
                        <form method="POST" action="{{ route('password.update') }}">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">
                            <div class="mb-3">
                                <label class="form-label">邮箱地址</label>
                                <input type="email" name="email" class="form-control" value="{{ $email ?? old('email') }}" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">新密码</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">确认新密码</label>
                                <input type="password" name="password_confirmation" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">重置密码</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
