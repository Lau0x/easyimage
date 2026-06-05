<?php
include_once __DIR__ . "/header.php";
?>
<link rel="stylesheet" href="<?php static_cdn(); ?>/public/static/EasyImage.css">
<link rel="stylesheet" href="<?php static_cdn(); ?>/public/static/viewjs/viewer.min.css">
<div class="piclite-page piclite-gallery-page">
    <div class="piclite-section-head">
        <div>
            <h1>上传历史</h1>
            <p>本地浏览器保存的上传记录</p>
        </div>
    </div>
    <div>
        <div id="viewjs" class="piclite-gallery-grid cards listNum">
            <!-- 历史上传列表 -->
        </div>
    </div>
</div>
<div class="piclite-history-actions history_clear">
</div>
<script type="application/javascript" src="<?php static_cdn(); ?>/public/static/EasyImage.js"></script>
<script type="application/javascript" src="<?php static_cdn(); ?>/public/static/lazyload/lazyload.min.js"></script>
<script type="application/javascript" src="<?php static_cdn(); ?>/public/static/viewjs/viewer.min.js"></script>
<script type="application/javascript" src="<?php static_cdn(); ?>/public/static/zui/lib/clipboard/clipboard.min.js"></script>
<script>
    if ($.zui.store.length() > 0) {
        console.log('saved: ' + $.zui.store.length()) // 获取总数
        $.zui.store.forEach(function(key, value) { // 遍历所有本地存储的条目
            console.log('url list: ' + value['url']) // 获取所有链接
            if (value['url'] !== undefined) {
                appendHistoryCard(key, value);
            }
        })
        $('.history_clear').append('<button class="btn btn-mini btn-primary history-clear-btn" type="button"><i class="icon icon-trash"></i> 清空历史记录</button>');
    } else {
        $('.listNum').append('<div class="piclite-empty-state"><i class="icon icon-history"></i><strong>暂无上传历史</strong><span>上传图片后会在当前浏览器保存记录</span></div>');
    };

    function appendIconLink(target, options) {
        $('<a>', {
            href: options.href || '#',
            target: options.target || undefined,
            rel: options.target ? 'noopener' : undefined,
            class: options.className || undefined,
            'data-clipboard-text': options.clipboard || undefined,
            'data-toggle': 'tooltip',
            title: options.title || ''
        }).append($('<i>', {
            class: 'icon ' + options.icon
        })).appendTo(target);
    }

    function appendHistoryCard(key, value) {
        let v_url = parseURL(value['url']);
        let thumb_url = value['thumb'] || value['url'];
        let src_name = value['srcName'] || key;
        let $actions = $('<div>', {
            class: 'bottom-bar piclite-card-actions'
        });

        let $image = $('<img>', {
            src: '../public/images/loading.svg',
            'data-image': thumb_url,
            'data-original': value['url'],
            alt: 'PicLite'
        }).on('error', function() {
            this.onerror = null;
            this.src = this.getAttribute('data-original') || '/public/images/404.png';
        });

        appendIconLink($actions, {
            href: value['url'],
            target: '_blank',
            icon: 'icon-picture',
            title: '打开'
        });
        appendIconLink($actions, {
            className: 'copy',
            clipboard: value['url'],
            icon: 'icon-copy',
            title: '复制链接'
        });
        appendIconLink($actions, {
            href: 'info.php?history=' + v_url.path,
            target: '_blank',
            icon: 'icon-info-sign',
            title: '详细信息'
        });
        appendIconLink($actions, {
            href: 'down.php?history=' + v_url.path,
            target: '_blank',
            icon: 'icon-cloud-download',
            title: '下载文件'
        });

        $('<a>', {
            href: '#',
            class: 'Remove',
            'data-toggle': 'tooltip',
            title: '删除记录'
        }).data('key', src_name).append($('<i>', {
            class: 'icon icon-remove-sign'
        })).appendTo($actions);

        appendIconLink($actions, {
            href: value['del'],
            target: '_blank',
            icon: 'icon-trash',
            title: '删除文件'
        });

        $('<a>', {
            href: '#',
            class: 'copy piclite-card-name',
            'data-clipboard-text': src_name,
            'data-toggle': 'tooltip',
            title: '源文件名'
        }).text(src_name).appendTo($actions);

        $('.listNum').append(
            $('<div>', {
                class: 'piclite-gallery-item'
            }).append(
                $('<div>', {
                    class: 'card piclite-image-card'
                }).append(
                    $('<div>', {
                        class: 'piclite-image-frame'
                    }).append($image),
                    $actions
                )
            )
        );
    }

    // 删除指定存储条目
    $('.Remove').on('click', function() {

        let Remove = $(this).data("key");
        $.zui.store.remove(Remove); // 删除指定存储条目

        new $.zui.Messager('已删除 ' + Remove + ' 上传记录', {
            type: "success", // 定义颜色主题 
            icon: "ok-sign" // 定义消息图标
        }).show();

        setTimeout(location.reload.bind(location), 2000); // 延迟2秒刷新
    })

    // 清空所有本地存储的条目
    $('.history-clear-btn').on('click', function() {
        new $.zui.Messager('已清空' + $.zui.store.length() + "条历史记录", {
            type: "success", // 定义颜色主题 
            icon: "ok-sign" // 定义消息图标
        }).show();

        $.zui.store.clear(); // 清空上传记录
        setTimeout(location.reload.bind(location), 2000); // 延迟2秒刷新
    })

    // 复制 文件名/URL
    var clipboard = new Clipboard('.copy');
    clipboard.on('success', function(e) {
        new $.zui.Messager("复制成功", {
            type: "success", // 定义颜色主题 
            icon: "ok-sign" // 定义消息图标
        }).show();

    });
    clipboard.on('error', function(e) {
        document.querySelector('.copy');
        new $.zui.Messager("复制失败", {
            type: "danger", // 定义颜色主题 
            icon: "exclamation-sign" // 定义消息图标
        }).show();
    });

    // viewjs
    var gallery = document.getElementById('viewjs');
    if (gallery) {
        new Viewer(gallery, {
            url: 'data-original',
        });
    }

    //懒加载
    var lazy = new Lazy({
        onload: function(elem) {
            console.log(elem)
        },
        delay: 300
    })

    // 更改网页标题
    document.title = "上传记录 - <?php echo $config['title']; ?>"
</script>
<?php
/** 引入底部 */
require_once __DIR__ . '/footer.php';
