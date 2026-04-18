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
                            <p class="brand-tagline">重置密码</p>
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
                        @if (session('status'))
                            <div class="alert alert-success">{{ session('status') }}</div>
                        @endif
                        <form method="POST" action="{{ route('password.email') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label">邮箱地址</label>
                                <input type="email" name="email" class="form-control" value="{{ old('email') }}" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">发送重置链接</button>
                        </form>
                        <div class="mt-3 text-center">
                            <a href="{{ route('login') }}">返回登录</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
