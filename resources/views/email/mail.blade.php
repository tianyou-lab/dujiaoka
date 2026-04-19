{{-- 邮件正文直接输出，$body 来自后端组装好的邮件模板（管理员可信维护），
     不走 purifyHtml 是因为它会过滤掉 <html><head><style><body> 等
     完整 HTML 文档必要标签，导致模板 CSS 变纯文本。--}}
{!! $body !!}
