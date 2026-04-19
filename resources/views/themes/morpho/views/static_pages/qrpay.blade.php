@extends('morpho::layouts.default')
@section('content')
<main class="content-wrapper">
  <section class="container py-5 mb-2 mb-md-3">
    <div class="row justify-content-center">
      <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0 rounded-4 p-4 text-center">
          <h4 class="mb-1">{{ $payname }}</h4>
          <p class="text-muted mb-3">{{ __('dujiaoka.scan_qrcode_to_pay') }}</p>

          <div class="d-flex justify-content-center mb-3">
            <div id="qrcode" style="width:220px;height:220px;"></div>
          </div>

          <div class="fs-4 fw-bold text-danger mb-2">
            {{ __('dujiaoka.money_symbol') }}{{ number_format($actual_price, 2) }}
          </div>
          <p class="text-muted small mb-0">{{ __('order.fields.order_sn') }}: {{ $orderid }}</p>
        </div>
      </div>
    </div>
  </section>
</main>
@stop
@section('js')
<script src="{{ asset('assets/common/js/qrcode.min.js') }}"></script>
<script>
new QRCode(document.getElementById("qrcode"), {
    text: @json($qr_code),
    width: 220,
    height: 220,
    colorDark: "#000000",
    colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.H
});
var _orderId = @json($orderid);
var _statusUrl = '/order/status/' + encodeURIComponent(_orderId);
var _detailUrl = '/order/detail/' + encodeURIComponent(_orderId);
var timer = setInterval(function(){
    fetch(_statusUrl, { credentials: 'same-origin' })
        .then(function(r){ return r.json().catch(function(){ return {}; }); })
        .then(function(data){
            if(data && data.code === 200){
                clearInterval(timer);
                window.location.href = _detailUrl;
            }
        })
        .catch(function(){ /* 轮询失败忽略，下次再试 */ });
}, 3000);
</script>
@stop
