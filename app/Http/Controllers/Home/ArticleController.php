<?php
namespace App\Http\Controllers\Home;

use App\Models\Articles;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController;

class ArticleController extends BaseController {
    
    public function listAll(){
        $articles = Articles::selectRaw('id, title, link, category, SUBSTRING(content, 1, 300) as content, updated_at')
        ->where('is_open', 1)
        ->orderBy('sort', 'desc')
        ->get()
        ->map(function ($article) {
            $article->summary = $article->getSummary();
            return $article;
        });
        $articles_sort = $articles->groupBy('category');
        $categories = $articles_sort->keys();
        
        return $this->render('static_pages/article', [
            'articles' => $articles_sort,
            'category' => $categories
        ], __('dujiaoka.page-title.article'));
    }
    
    public function show($link) {
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,128}$/', $link)) {
            abort(404);
        }

        $article = Articles::with(['goods' => function($query) {
            $query->where('is_open', true)->select('goods.id', 'goods.gd_name', 'goods.gd_description', 'goods.picture');
        }])->where('link', $link)->where('is_open', 1)->first();

        if (!$article) {
            abort(404);
        }

        $title = $article->title;
        $content = $article->content;
        $relatedGoods = $article->goods;
        
        return $this->render('static_pages/article', [
        'title' => $title,
        'content' => $content,
        'relatedGoods' => $relatedGoods
        ],
        $title." | ". __('dujiaoka.page-title.article'));
    }
    
}