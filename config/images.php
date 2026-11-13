<?php

/*
 * 오더하우 전용 리사이저(cloudv)의 허용 목록. orderhow docs/work/storage-r2-migration/plan.md §3.
 *
 * allowed_buckets 가 비어 있으면 아무 제한도 걸지 않는다(CT117 의 기존 동작 — disclo-bot 등 여러 버킷을 서빙).
 * 설정한 서버에서만 버킷·원본 확장자·크기를 검사한다.
 */
return [

    /*
     * 서빙할 버킷. cloudv 는 IMAGE_ALLOWED_BUCKETS=orderhow.
     */
    'allowed_buckets' => array_values(array_filter(array_map('trim', explode(',', (string) env('IMAGE_ALLOWED_BUCKETS', ''))))),

    /*
     * 버킷별 허용 크기. 표기는 라우트와 같고 c·! 는 같은 크롭으로 본다. 목록이 없는 버킷은 3000 이하 전부.
     * 코드가 만드는 크기와 NPM 로그 실사용 크기(2026-11-05~13, research.md §5.3), 전단·배너 2000x0.
     */
    'sizes' => [
        'orderhow' => ['400x400c', '1080x1080', '0x200', '0x240', '0x140', '0x60', '0x80', '0x150', '0x400', '100x100c', '300x300c', '2000x0'],
    ],

    /*
     * 목록 밖 크기를 404 로 막을지(true) 로그(image.size.unlisted)만 남길지(false). 전환 첫 주는 false 로 두고 로그를 본 뒤 켠다.
     */
    'enforce_sizes' => (bool) env('IMAGE_ENFORCE_SIZES', false),

    /*
     * 원본 라우트가 돌려줄 확장자. 사진이 아닌 파일(binlog·엑셀·zip·json)이 같은 버킷에 있었다.
     */
    'original_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff'],

];
