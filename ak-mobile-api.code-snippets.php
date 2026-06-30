<?php
/**
 * AK Workspace – طبقة API للتطبيق الجوال (Native iOS)
 * ════════════════════════════════════════════════════════
 * Code Snippets → إضافة جديد → PHP → "Run everywhere"
 * تُسجّل مسارات REST تحت /wp-json/ak/v1/ يقرأها تطبيق الآيفون.
 * المصادقة: حساب ووردبريس + Application Password (Basic Auth عبر HTTPS).
 * تعتمد على دوال أودو في سنيبت "لوحة فواتير Odoo" (ak_odoo_rpc/ak_odoo_uid).
 * ════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* صلاحية الوصول: مستخدم مسجّل بصلاحية إدارة */
function ak_mobile_can() {
    return current_user_can( 'manage_options' );
}

/* سماحية CORS لمسارات ak/v1 (لتعمل إضافة Word المستضافة على نطاق آخر) */
add_action( 'rest_api_init', function () {
    add_filter( 'rest_pre_serve_request', function ( $served, $result, $request ) {
        if ( strpos( (string) $request->get_route(), '/ak/v1' ) === 0 ) {
            $origin = function_exists( 'get_http_origin' ) ? get_http_origin() : '';
            header( 'Access-Control-Allow-Origin: ' . ( $origin ? esc_url_raw( $origin ) : '*' ) );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
            header( 'Vary: Origin' );
        }
        return $served;
    }, 20, 3 );
}, 20 );

/* مجمّع مبالغ سريع من أودو عبر read_group */
function ak_mobile_sum( $uid, array $domain, string $field ) {
    if ( ! function_exists( 'ak_odoo_rpc' ) ) return 0.0;
    $r = ak_odoo_rpc( 'object', 'execute_kw', [
        AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
        'account.move', 'read_group',
        [ $domain, [ $field ], [] ],
    ] );
    if ( is_array( $r ) && isset( $r[0][ $field ] ) ) return (float) $r[0][ $field ];
    return 0.0;
}

/* اسم الشريك من حقل أودو [id, "name"] */
function ak_mobile_partner_name( $pid ) {
    return ( is_array( $pid ) && isset( $pid[1] ) ) ? $pid[1] : '';
}

/* أول حرفين من اسم للأڤاتار */
function ak_mobile_initials( $name ) {
    $name = trim( (string) $name );
    if ( $name === '' ) return '–';
    $parts = preg_split( '/\s+/u', $name );
    $a = mb_substr( $parts[0], 0, 1 );
    $b = isset( $parts[1] ) ? mb_substr( $parts[1], 0, 1 ) : mb_substr( $parts[0], 1, 1 );
    return $a . $b;
}

/* مسمّى تصنيف التحصيل (مطابق لصفحة ديون العملاء) */
if ( ! function_exists( 'ak_recv_tag_label' ) ) {
function ak_recv_tag_label( $tag ) {
    $m = [
        'execution_against' => 'رفع تنفيذ ضدهم',
        'lawsuit_filed'     => 'قضية للمطالبة بالأتعاب',
        'after_verdict'     => 'مستحقة بعد الحكم',
        'execution_for'     => 'تنفيذ على خصومهم',
    ];
    return $m[ $tag ] ?? '';
}
}

/* ===== مرجعية الأنظمة السعودية: جلب رسمي من هيئة الخبراء + استرجاع المواد ذات الصلة ===== */
if ( ! function_exists( 'ak_build_sys' ) ) {
function ak_build_sys( $messages, $doc_type ) {
    $guide = '';
    $cache = get_option( 'ak_editor_style_guide_cache' );
    if ( is_array( $cache ) && ! empty( $cache['guide'] ) ) $guide = (string) $cache['guide'];

    /* مرجعية الأنظمة الرسمية ذات الصلة */
    $caseText = '';
    foreach ( $messages as $cm ) { if ( ( $cm['role'] ?? 'user' ) !== 'model' ) $caseText .= ' ' . (string) ( $cm['text'] ?? '' ); }
    $caseText .= ' ' . $doc_type;
    $lawCtx = '';
    if ( function_exists( 'ak_detect_domains' ) ) {
        $domains = ak_detect_domains( $caseText );
        $per = count( $domains ) ? max( 2500, (int) ( 11000 / count( $domains ) ) ) : 0;
        foreach ( $domains as $dom ) {
            $ctx = ak_law_relevant( $dom, $caseText, $per );
            if ( $ctx !== '' ) { $nm = ak_law_sources()[ $dom ]['name'] ?? ''; $lawCtx .= "\n\n【 " . $nm . " 】\n" . $ctx; }
        }
        $lawCtx = mb_substr( trim( $lawCtx ), 0, 12000 );
    }

    /* نماذج فعلية من مكتبة الصياغات */
    $docKey = function_exists( 'ak_detect_doctype' ) ? ak_detect_doctype( $caseText ) : '';
    $libEx  = ( $docKey && function_exists( 'ak_lib_examples' ) ) ? ak_lib_examples( $docKey ) : '';

    /* مراجع من المكتبة القضائية (بحث دلالي) */
    $qadhaCtx = function_exists( 'ak_qadha_retrieve' ) ? ak_qadha_retrieve( $caseText ) : '';

    $sys = "أنت مساعد قانوني خبير في الأنظمة واللوائح السعودية تعمل لدى مكتب محاماة، تحلّل القضايا وتصوغ المستندات (صحيفة دعوى، مذكرات جوابية، لوائح اعتراض، عقود).\n"
        . "قواعد صارمة التزم بها:\n"
        . "1) استخرج البيانات من المرفقات والوقائع حرفياً ودقيقاً (الأسماء، الهويات، التواريخ، المبالغ، الأرقام). يُمنَع منعاً باتاً استخدام عبارات نائبة مثل «[اسم العامل]» أو «[اسم المنشأة]»؛ وإن تعذّر قراءة معلومة فاذكر ذلك صراحةً واطلبها من المحامي.\n"
        . "2) طبّق المواد النظامية بدقّة وميّز بين المتشابهة. تنبيه مهم: من يترك العمل بموجب المادة (81) من نظام العمل يحتفظ بكامل حقوقه ويستحق مكافأة نهاية الخدمة **كاملةً**، ولا تُطبَّق عليه تخفيضات الاستقالة الواردة في المادة (87). لا تخلط بين الترك بموجب المادة (81) والاستقالة.\n"
        . "3) أي افتراض (مثل افتراض عدم استخدام رصيد الإجازات) اذكره صراحةً كافتراضٍ لا كحقيقة، ونبّه إلى وجوب التحقق من السجلات.\n"
        . "4) راجع كل عملية حسابية خطوة بخطوة وتأكّد من صحتها رقمياً قبل عرض النتيجة.\n"
        . "5) عند التحليل في المحادثة: اعرض الأساس النظامي (أرقام المواد) وخطوات الحساب والافتراضات بوضوح. وعند كتابة المستند النهائي: أخرج نصاً عادياً منظّماً بأسلوب المكتب بلا تعليقات أو رموز.\n"
        . "إذا نقصت معلومة جوهرية فاسأل عنها باختصار قبل الصياغة. "
        . "اكتب المستندات بأسلوب **مختصر وواضح واحترافي** بلا حشو أو تكرار أو إطالة، وادخل في صلب الموضوع مباشرةً، مع الالتزام التام بأسلوب نماذج المكتب أدناه إن وُجدت."
        . " عند تفريغ تسجيل صوتي أو مرئي مرفق: فرّغ محتواه **كاملاً من أوله إلى آخره دون اختصار أو حذف**، واكتبه **نصاً متواصلاً في فقرة واحدة جارية — لا تبدأ سطراً جديداً لكل عبارة**، مع الإشارة للمتحدث داخل النص بصيغة «اسم المتحدث: كلامه.» يتبعه المتحدث التالي مباشرةً في نفس الفقرة، بدون ذكر التوقيت أو الثواني أو أي أرقام زمنية. وإذا لم يُعرَف اسم المتحدث فاكتب وصفاً مختصراً له (مثل: المتحدث الأول)."
        . " عند كتابة **صحيفة دعوى** تحديداً: بعد المطلع (المحكمة والأطراف والتحية)، قسّم المستند إلى ثلاثة أقسام بعناوين بارزة وبهذا الترتيب: (1) «موضوع الدعوى» — الوقائع وملخص النزاع. (2) «طلبات المدعي» — ما يُطلب من المحكمة بوضوح. (3) «الأسانيد» — الأساس النظامي وأرقام المواد والمبادئ القضائية المؤيِّدة. هذا التقسيم خاص بصحيفة الدعوى فقط، لا يُطبَّق على المذكرات أو اللوائح أو العقود."
        . ( $lawCtx !== '' ? "\n\nالنصوص الرسمية لمواد النظام ذات الصلة (المصدر: هيئة الخبراء بمجلس الوزراء). استند إليها حرفياً، وتحقّق من أرقام المواد ونصوصها منها قبل أي استنتاج، ولا تعتمد على ذاكرتك في أرقام المواد:\n" . $lawCtx : "" )
        . ( $libEx !== '' ? "\n\nنماذج فعلية من مستندات بنفس النوع صاغها المكتب — هذه هي المرجع الأساسي لأسلوب الكتابة. تعلّم منها: المصطلحات، صيغ الافتتاح والختام، بنية المستند، نبرة الصياغة، ومستوى الاختصار. التزم بأسلوبها بدقة، دون نسخ وقائعها أو أرقامها:\n\n" . $libEx : "" )
        . ( $qadhaCtx !== '' ? "\n\nمراجع من المكتبة القضائية الرسمية (كتب وأبحاث ومجلات قضائية سعودية) ذات صلة بالموضوع — استأنس بها في التأصيل والمبادئ القضائية والاجتهادات، واذكر المصدر عند الاقتباس منها:\n" . $qadhaCtx : "" )
        . ( $guide !== '' ? "\n\nدليل أسلوب المكتب الموجز:\n" . $guide : "" );
    return $sys;
}
}
if ( ! function_exists( 'ak_case_process' ) ) {
function ak_case_process( $messages, $attachments, $mode, $doc_type, $model = '' ) {
    $key = get_option( 'ak_gemini_api_key', '' );
    if ( ! $key ) return [ 'ok' => false, 'error' => 'مفتاح الذكاء غير مضبوط' ];
    $sys = ak_build_sys( $messages, $doc_type );

    $contents = [];
    $n = count( $messages );
    foreach ( $messages as $i => $m ) {
        $role  = ( ( $m['role'] ?? 'user' ) === 'model' ) ? 'model' : 'user';
        $parts = [ [ 'text' => (string) ( $m['text'] ?? '' ) ] ];
        if ( $role === 'user' && $i === $n - 1 ) {
            foreach ( $attachments as $att ) {
                $mime = (string) ( $att['mime'] ?? '' );
                $uri  = (string) ( $att['uri'] ?? '' );
                $b64  = (string) ( $att['data'] ?? '' );
                if ( $uri && $mime )      $parts[] = [ 'file_data'   => [ 'mime_type' => $mime, 'file_uri' => $uri ] ];
                elseif ( $mime && $b64 )  $parts[] = [ 'inline_data' => [ 'mime_type' => $mime, 'data'     => $b64 ] ];
            }
        }
        $contents[] = [ 'role' => $role, 'parts' => $parts ];
    }
    if ( $mode === 'generate' ) {
        $dt = $doc_type ?: 'المستند القانوني المطلوب';
        $contents[] = [ 'role' => 'user', 'parts' => [ [ 'text' => "بناءً على كل ما سبق وعلى المرفقات، اكتب الآن «{$dt}» كاملةً وجاهزةً للاستخدام، بهيكل نظامي صحيح وبأسلوب المكتب، نصاً عادياً فقط بلا أي تعليقات أو مقدمات." ] ] ];
    }

    $payload = [
        'system_instruction' => [ 'parts' => [ [ 'text' => $sys ] ] ],
        'contents'           => $contents,
        'generationConfig'   => [ 'temperature' => 0.3, 'maxOutputTokens' => 16384 ],
    ];
    $allowed = [ 'gemini-2.5-flash', 'gemini-3.5-flash', 'gemini-2.5-pro', 'gemini-3.1-pro-preview' ];
    if ( ! in_array( $model, $allowed, true ) ) $model = 'gemini-2.5-pro';
    $url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode( $key );
    /* نداء واحد بمهلة طويلة — ننتظر برو حتى يكمّل المستند (السيرفر الآن يسمح بـ٣٦٠ ثانية) */
    $resp = wp_remote_post( $url, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $payload ),
        'timeout' => 300,
    ] );
    if ( is_wp_error( $resp ) ) return [ 'ok' => false, 'error' => 'تأخّر الذكاء في الرد — أعد المحاولة' ];
    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    $out  = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ( trim( (string) $out ) === '' ) {
        $emsg = $data['error']['message'] ?? 'لا نتيجة من الذكاء';
        return [ 'ok' => false, 'error' => 'تعذّر التوليد: ' . $emsg ];
    }
    return [ 'ok' => true, 'reply' => trim( $out ) ];
}
}
if ( ! function_exists( 'ak_qadha_create_table' ) ) {
function ak_qadha_create_table() {
    global $wpdb;
    $t  = $wpdb->prefix . 'ak_qadha_chunks';
    $cs = $wpdb->get_charset_collate();
    $wpdb->query( "CREATE TABLE IF NOT EXISTS $t ( id INT NOT NULL, b INT NOT NULL, body LONGTEXT, vec TEXT, PRIMARY KEY (id), KEY b (b) ) $cs" );
}
}
if ( ! function_exists( 'ak_qadha_unpack' ) ) {
function ak_qadha_unpack( $b64 ) {
    $bin = base64_decode( (string) $b64, true );
    if ( $bin === false || $bin === '' ) return [];
    $a = @unpack( 'f*', $bin );
    return $a ? array_values( $a ) : [];
}
}
if ( ! function_exists( 'ak_qadha_dot' ) ) {
function ak_qadha_dot( $a, $b ) {
    $s = 0.0; $n = min( count( $a ), count( $b ) );
    for ( $i = 0; $i < $n; $i++ ) $s += $a[ $i ] * $b[ $i ];
    return $s;
}
}
if ( ! function_exists( 'ak_qadha_embed_query' ) ) {
function ak_qadha_embed_query( $text ) {
    $key = get_option( 'ak_gemini_api_key', '' );
    if ( ! $key ) return null;
    $v = null;
    for ( $att = 0; $att < 3 && ! is_array( $v ); $att++ ) {
        if ( $att ) usleep( 500000 );
        $resp = wp_remote_post( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent?key=' . rawurlencode( $key ), [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'model' => 'models/gemini-embedding-001', 'content' => [ 'parts' => [ [ 'text' => mb_substr( (string) $text, 0, 8000 ) ] ] ], 'taskType' => 'RETRIEVAL_QUERY', 'outputDimensionality' => 768 ] ),
            'timeout' => 25,
        ] );
        if ( is_wp_error( $resp ) ) continue;
        $d = json_decode( wp_remote_retrieve_body( $resp ), true );
        $v = $d['embedding']['values'] ?? null;
    }
    if ( ! is_array( $v ) ) return null;
    $m = 0.0; foreach ( $v as $x ) $m += $x * $x; $m = sqrt( $m ) ?: 1.0;
    foreach ( $v as $i => $x ) $v[ $i ] = $x / $m;
    return $v;
}
}
if ( ! function_exists( 'ak_qadha_retrieve' ) ) {
function ak_qadha_retrieve( $queryText, $topBooks = 4, $topChunks = 5, $maxChars = 6000 ) {
    global $wpdb;
    $t = $wpdb->prefix . 'ak_qadha_chunks';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) return '';
    $books = get_option( 'ak_qadha_books' );
    if ( ! is_array( $books ) || ! $books ) return '';
    $qv = ak_qadha_embed_query( $queryText );
    if ( ! $qv ) return '';
    $bs = [];
    foreach ( $books as $i => $bk ) { $c = ak_qadha_unpack( $bk['centroid'] ?? '' ); $bs[ $i ] = $c ? ak_qadha_dot( $qv, $c ) : -1; }
    arsort( $bs );
    $top = array_slice( array_keys( $bs ), 0, $topBooks );
    if ( ! $top ) return '';
    $in   = implode( ',', array_map( 'intval', $top ) );
    $rows = $wpdb->get_results( "SELECT b, body, vec FROM $t WHERE b IN ($in)", ARRAY_A );
    if ( ! $rows ) return '';
    $cs = [];
    foreach ( $rows as $idx => $r ) { $v = ak_qadha_unpack( $r['vec'] ); $cs[ $idx ] = $v ? ak_qadha_dot( $qv, $v ) : -1; }
    arsort( $cs );
    $pick = array_slice( array_keys( $cs ), 0, $topChunks );
    $out  = '';
    foreach ( $pick as $idx ) {
        $r     = $rows[ $idx ];
        $title = $books[ (int) $r['b'] ]['title'] ?? '';
        $out  .= "\n\n【 من: " . $title . " 】\n" . trim( (string) $r['body'] );
        if ( mb_strlen( $out ) > $maxChars ) break;
    }
    return mb_substr( trim( $out ), 0, $maxChars );
}
}
if ( ! function_exists( 'ak_law_defs' ) ) {
function ak_law_defs() {
    return [
        'labor'           => [ 'name' => 'نظام العمل',              'id' => '08381293-6388-48e2-8ad2-a9a700f2aa94', 'kw' => [ 'عمل','عامل','أجر','راتب','نهاية الخدمة','إجازة','استقالة','فصل','صاحب العمل','إضافي' ] ],
        'civil_procedure' => [ 'name' => 'نظام المرافعات الشرعية',  'id' => 'f0eaae46-9f84-40ee-815e-a9a700f268b3', 'kw' => [ 'دعوى','مرافعة','جلسة','اختصاص','تبليغ','إجراءات','صحيفة','المحكمة','جلسات' ] ],
        'evidence'        => [ 'name' => 'نظام الإثبات',            'id' => '2716057c-c097-4bad-8e1e-ae1400c678d5', 'kw' => [ 'إثبات','بيّنة','بينة','شهادة','إقرار','يمين','قرينة','خبرة','مستند' ] ],
        'civil'           => [ 'name' => 'نظام المعاملات المدنية',  'id' => '655fdb42-8c96-422b-b8c4-b04f0095c94c', 'kw' => [ 'عقد','التزام','تعويض','ضرر','بيع','إيجار','ملكية','فسخ','معاملات' ] ],
        'criminal'        => [ 'name' => 'نظام الإجراءات الجزائية', 'id' => '8f1b7079-a5f0-425d-b5e0-a9a700f26b2d', 'kw' => [ 'جزائي','جريمة','متهم','نيابة','توقيف','عقوبة','جنائي','جناية' ] ],
        'execution'       => [ 'name' => 'نظام التنفيذ',            'id' => 'c81ba2f1-1bf1-443b-9b1c-a9a700f27110', 'kw' => [ 'تنفيذ','حجز','سند تنفيذي','إعسار','مماطلة','محكمة التنفيذ' ] ],
    ];
}
}
if ( ! function_exists( 'ak_law_sources' ) ) {
function ak_law_sources() {
    $o = [];
    foreach ( ak_law_defs() as $k => $v ) $o[ $k ] = [ 'name' => $v['name'], 'id' => $v['id'] ];
    return $o;
}
}
if ( ! function_exists( 'ak_law_text' ) ) {
function ak_law_text( $domain ) {
    $src = ak_law_sources()[ $domain ] ?? null;
    if ( ! $src ) return '';
    $ck = 'ak_law_text_' . $domain;
    $c  = get_transient( $ck );
    if ( $c !== false ) return $c;
    $resp = wp_remote_get( 'https://laws.boe.gov.sa/BoeLaws/Laws/LawDetails/' . $src['id'] . '/1', [ 'timeout' => 15 ] );
    if ( is_wp_error( $resp ) ) return '';
    $text = wp_strip_all_tags( wp_remote_retrieve_body( $resp ) );
    $text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
    $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
    if ( $text !== '' ) set_transient( $ck, $text, MONTH_IN_SECONDS );
    return $text;
}
}
if ( ! function_exists( 'ak_detect_domains' ) ) {
function ak_detect_domains( $text, $max = 3 ) {
    $hits = [];
    foreach ( ak_law_defs() as $dom => $def ) {
        foreach ( $def['kw'] as $k ) { if ( mb_strpos( (string) $text, $k ) !== false ) { $hits[] = $dom; break; } }
    }
    return array_slice( array_values( array_unique( $hits ) ), 0, $max );
}
}
if ( ! function_exists( 'ak_law_relevant' ) ) {
function ak_law_relevant( $domain, $query, $maxChars = 6000 ) {
    $text = ak_law_text( $domain );
    if ( $text === '' ) return '';
    $parts = preg_split( '/(?=المادة)/u', $text );
    if ( ! is_array( $parts ) ) return '';
    $def = ak_law_defs()[ $domain ] ?? null;
    $kw  = $def ? $def['kw'] : [];
    if ( preg_match_all( '/المادة[^0-9٠-٩]{0,6}([0-9٠-٩]{1,3})/u', (string) $query, $mm ) ) {
        foreach ( $mm[0] as $ref ) $kw[] = $ref;
    }
    $picked = '';
    foreach ( $parts as $p ) {
        foreach ( $kw as $k ) {
            if ( $k !== '' && mb_strpos( $p, $k ) !== false ) { $picked .= $p . "\n"; break; }
        }
        if ( mb_strlen( $picked ) > $maxChars ) break;
    }
    return mb_substr( $picked, 0, $maxChars );
}
}

/* كشف نوع المستند المطلوب من نص الطلب (يطابق تصنيف مكتبة الصياغات) */
if ( ! function_exists( 'ak_detect_doctype' ) ) {
function ak_detect_doctype( $text ) {
    $map = [
        'صحيفة دعوى' => 'claim_statement', 'صحيفة الدعوى' => 'claim_statement', 'لائحة دعوى' => 'claim_statement',
        'مذكرة جوابية' => 'reply_memo', 'مذكرة دفاع' => 'defense_memo', 'مذكرة جزائية' => 'criminal_memo',
        'استئناف جزائي' => 'criminal_appeal', 'لائحة اعتراض' => 'objection', 'اعتراض' => 'objection',
        'عقد' => 'contract', 'إنذار' => 'notice', 'خطاب' => 'notice', 'صلح' => 'settlement',
        'مخالصة' => 'clearance', 'مذكرة' => 'reply_memo',
    ];
    foreach ( $map as $k => $v ) { if ( mb_strpos( (string) $text, $k ) !== false ) return $v; }
    return '';
}
}

/* جلب نماذج فعلية من مكتبة الصياغات حسب نوع المستند (ليتعلّم منها الذكاء الأسلوب) */
if ( ! function_exists( 'ak_lib_examples' ) ) {
function ak_lib_examples( $docKey, $limit = 2, $maxChars = 6500 ) {
    global $wpdb;
    $lib = $wpdb->prefix . 'ak_writing_library_docs';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$lib'" ) !== $lib ) return '';
    $order = "ORDER BY FIELD(quality,'excellent','good','medium','review','weak') ASC";
    $rows = [];
    if ( $docKey ) {
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT full_text, excerpt FROM `$lib` WHERE duplicate_of=0 AND doc_type=%s $order LIMIT %d", $docKey, $limit ), ARRAY_A );
    }
    if ( empty( $rows ) ) {
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT full_text, excerpt FROM `$lib` WHERE duplicate_of=0 $order LIMIT %d", $limit ), ARRAY_A );
    }
    if ( empty( $rows ) ) return '';
    $parts = []; $tot = 0;
    foreach ( $rows as $r ) {
        $t = trim( (string) ( $r['full_text'] ?? '' ) );
        if ( $t === '' ) $t = trim( (string) ( $r['excerpt'] ?? '' ) );
        if ( $t === '' ) continue;
        $t = mb_substr( $t, 0, 2800 );
        $parts[] = $t; $tot += mb_strlen( $t );
        if ( $tot > $maxChars ) break;
    }
    return implode( "\n\n=====\n\n", $parts );
}
}

function ak_mobile_safe_text( $value ) {
    if ( is_array( $value ) ) return isset( $value[1] ) ? (string) $value[1] : '';
    if ( $value === false || $value === null ) return '';
    return (string) $value;
}

function ak_mobile_docs_breadcrumb( $uid, $folder_id ) {
    $crumbs = [];
    $fid = (int) $folder_id;
    $guard = 0;
    while ( $fid && $guard < 25 ) {
        $row = ak_odoo_rpc( 'object', 'execute_kw', [
            AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
            'documents.document', 'read',
            [ [ $fid ] ],
            [ 'fields' => [ 'id', 'name', 'folder_id' ] ],
        ] );
        if ( empty( $row ) || ! is_array( $row ) ) break;
        $doc = $row[0];
        $crumbs[] = [
            'id'   => (int) ( $doc['id'] ?? $fid ),
            'name' => ak_mobile_safe_text( $doc['name'] ?? '' ),
        ];
        $fid = ( ! empty( $doc['folder_id'] ) && is_array( $doc['folder_id'] ) ) ? (int) $doc['folder_id'][0] : 0;
        $guard++;
    }
    return array_reverse( $crumbs );
}

function ak_mobile_doc_item( array $doc ) {
    $folder = $doc['folder_id'] ?? null;
    return [
        'id'        => (int) ( $doc['id'] ?? 0 ),
        'name'      => ak_mobile_safe_text( $doc['name'] ?? '' ),
        'type'      => ak_mobile_safe_text( $doc['type'] ?? '' ),
        'mimetype'  => ak_mobile_safe_text( $doc['mimetype'] ?? '' ),
        'size'      => (int) ( $doc['file_size'] ?? 0 ),
        'folder_id' => ( is_array( $folder ) && isset( $folder[0] ) ) ? (int) $folder[0] : 0,
    ];
}

function ak_mobile_odoo_existing_fields( string $model, int $uid, array $fields ) {
    static $cache = [];
    $key = $model . ':' . $uid;

    if ( ! array_key_exists( $key, $cache ) ) {
        $available = [];
        if ( function_exists( 'ak_odoo_rpc' ) ) {
            $available = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
                $model, 'fields_get',
                [],
                [ 'attributes' => [ 'string' ] ],
            ] );
        }
        $cache[ $key ] = is_array( $available ) ? array_keys( $available ) : [];
    }

    if ( empty( $cache[ $key ] ) ) {
        return $fields;
    }

    return array_values( array_intersect( $fields, $cache[ $key ] ) );
}

function ak_mobile_contacts_list( $req ) {
    $uid = ak_odoo_uid();
    if ( ! $uid ) return new WP_Error( 'odoo', 'Odoo connection failed', [ 'status' => 502 ] );

    $search = sanitize_text_field( $req['search'] ?? '' );
    $domain = [];
    if ( $search !== '' ) {
        $domain = [
            '|', '|',
            [ 'name', 'ilike', $search ],
            [ 'email', 'ilike', $search ],
            [ 'phone', 'ilike', $search ],
        ];
    }

    $fields = ak_mobile_odoo_existing_fields( 'res.partner', $uid, [
        'id', 'name', 'display_name', 'email', 'phone', 'city',
        'street', 'street2', 'website', 'vat', 'function', 'company_name',
        'parent_id', 'child_ids', 'is_company', 'customer_rank', 'supplier_rank',
    ] );

    $rows = [];
    if ( function_exists( 'ak_odoo_rpc' ) ) {
        $rows = ak_odoo_rpc( 'object', 'execute_kw', [
            AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
            'res.partner', 'search_read',
            [ $domain ],
            [
                'fields'  => $fields,
                'limit'   => 300,
                'order'   => 'name asc',
                'context' => [ 'active_test' => false ],
            ],
        ] );
    }

    if ( ! is_array( $rows ) && defined( 'AK_ODOO_URL' ) ) {
        $raw = wp_remote_post( rtrim( AK_ODOO_URL, '/' ) . '/jsonrpc', [
            'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
            'body'    => wp_json_encode( [
                'jsonrpc' => '2.0',
                'method'  => 'call',
                'id'      => 1,
                'params'  => [
                    'service' => 'object',
                    'method'  => 'execute_kw',
                    'args'    => [
                        AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
                        'res.partner', 'search_read',
                        [ $domain ],
                        [
                            'fields'  => $fields,
                            'limit'   => 300,
                            'order'   => 'name asc',
                            'context' => [ 'active_test' => false ],
                        ],
                    ],
                ],
            ] ),
            'timeout' => 30,
        ] );
        if ( is_wp_error( $raw ) ) return new WP_Error( 'odoo_http', $raw->get_error_message(), [ 'status' => 502 ] );
        $body = json_decode( wp_remote_retrieve_body( $raw ), true );
        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'odoo_error', $body['error']['data']['message'] ?? 'Odoo error', [ 'status' => 502 ] );
        }
        $rows = $body['result'] ?? [];
    }

    if ( ! is_array( $rows ) ) $rows = [];

    $child_ids = [];
    foreach ( $rows as $r ) {
        if ( ! empty( $r['child_ids'] ) && is_array( $r['child_ids'] ) ) {
            foreach ( $r['child_ids'] as $cid ) {
                $child_ids[] = (int) $cid;
            }
        }
    }

    $children_by_parent = [];
    $child_ids = array_values( array_unique( array_filter( $child_ids ) ) );
    if ( $child_ids && function_exists( 'ak_odoo_rpc' ) ) {
        $child_fields = ak_mobile_odoo_existing_fields( 'res.partner', $uid, [
            'id', 'name', 'email', 'phone', 'city', 'function', 'parent_id', 'is_company',
        ] );
        $child_rows = ak_odoo_rpc( 'object', 'execute_kw', [
            AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
            'res.partner', 'read',
            [ $child_ids ],
            [ 'fields' => $child_fields ],
        ] ) ?: [];

        if ( is_array( $child_rows ) ) {
            foreach ( $child_rows as $ch ) {
                $parent = $ch['parent_id'] ?? null;
                $parent_id = ( is_array( $parent ) && isset( $parent[0] ) ) ? (int) $parent[0] : 0;
                if ( ! $parent_id ) continue;

                $children_by_parent[ $parent_id ][] = [
                    'id'         => (int) ( $ch['id'] ?? 0 ),
                    'name'       => ak_mobile_safe_text( $ch['name'] ?? '' ),
                    'sub'        => ak_mobile_safe_text( $ch['function'] ?? '' ),
                    'initials'   => ak_mobile_initials( $ch['name'] ?? '' ),
                    'phone'      => ak_mobile_safe_text( $ch['phone'] ?? '' ),
                    'email'      => ak_mobile_safe_text( $ch['email'] ?? '' ),
                    'city'       => ak_mobile_safe_text( $ch['city'] ?? '' ),
                    'function'   => ak_mobile_safe_text( $ch['function'] ?? '' ),
                    'is_company' => (bool) ( $ch['is_company'] ?? false ),
                ];
            }
        }
    }

    $out = [];
    foreach ( $rows as $r ) {
        $phone = $r['phone'] ?? '';
        $street = trim( ak_mobile_safe_text( $r['street'] ?? '' ) . ' ' . ak_mobile_safe_text( $r['street2'] ?? '' ) );
        $is_company = (bool) ( $r['is_company'] ?? false );
        $city = ak_mobile_safe_text( $r['city'] ?? '' );
        $rank = [];
        if ( ! empty( $r['customer_rank'] ) ) $rank[] = 'Customer';
        if ( ! empty( $r['supplier_rank'] ) ) $rank[] = 'Supplier';
        $sub_parts = array_filter( [
            $is_company ? 'Company' : 'Person',
            $city,
            implode( ' / ', $rank ),
        ] );

        $out[] = [
            'id'            => (int) ( $r['id'] ?? 0 ),
            'name'          => ak_mobile_safe_text( $r['name'] ?? '' ),
            'sub'           => implode( ' - ', $sub_parts ),
            'initials'      => ak_mobile_initials( $r['name'] ?? '' ),
            'phone'         => ak_mobile_safe_text( $phone ),
            'email'         => ak_mobile_safe_text( $r['email'] ?? '' ),
            'city'          => $city,
            'street'        => $street,
            'website'       => ak_mobile_safe_text( $r['website'] ?? '' ),
            'vat'           => ak_mobile_safe_text( $r['vat'] ?? '' ),
            'function'      => ak_mobile_safe_text( $r['function'] ?? '' ),
            'company_name'  => ak_mobile_safe_text( $r['company_name'] ?? '' ),
            'is_company'    => $is_company,
            'customer_rank' => (int) ( $r['customer_rank'] ?? 0 ),
            'supplier_rank' => (int) ( $r['supplier_rank'] ?? 0 ),
            'children'      => $children_by_parent[ (int) ( $r['id'] ?? 0 ) ] ?? [],
        ];
    }

    return $out;
}

add_action( 'rest_api_init', function () {

    /* ① فحص الاتصال + هوية المستخدم */
    register_rest_route( 'ak/v1', '/ping', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function () {
            $u   = wp_get_current_user();
            $uid = function_exists( 'ak_odoo_uid' ) ? ak_odoo_uid() : null;
            return [
                'ok'    => true,
                'user'  => $u->display_name,
                'odoo'  => $uid ? 'connected' : 'unavailable',
            ];
        },
    ] );

    /* ② الملخّص المالي (بطاقات المالية) */
    register_rest_route( 'ak/v1', '/finance/summary', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function () {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'فشل الاتصال بأودو', [ 'status' => 502 ] );

            $ys = wp_date( 'Y' ) . '-01-01';
            $revenue     = ak_mobile_sum( $uid, [ [ 'move_type', '=', 'out_invoice' ], [ 'state', '=', 'posted' ], [ 'invoice_date', '>=', $ys ] ], 'amount_total' );
            $expenses    = ak_mobile_sum( $uid, [ [ 'move_type', '=', 'in_invoice' ],  [ 'state', '=', 'posted' ], [ 'invoice_date', '>=', $ys ] ], 'amount_total' );
            $receivables = ak_mobile_sum( $uid, [ [ 'move_type', '=', 'out_invoice' ], [ 'state', '=', 'posted' ], [ 'amount_residual', '>', 0 ] ], 'amount_residual' );

            return [
                'revenue'     => round( $revenue, 2 ),
                'expenses'    => round( $expenses, 2 ),
                'profit'      => round( $revenue - $expenses, 2 ),
                'receivables' => round( $receivables, 2 ),
                'currency'    => 'SAR',
                'year'        => (int) wp_date( 'Y' ),
            ];
        },
    ] );

    /* ③ الفواتير */
    register_rest_route( 'ak/v1', '/invoices', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function () {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'فشل الاتصال بأودو', [ 'status' => 502 ] );

            $rows = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
                'account.move', 'search_read',
                [ [ [ 'move_type', '=', 'out_invoice' ], [ 'state', '=', 'posted' ] ] ],
                [
                    'fields' => [ 'name', 'partner_id', 'amount_total', 'amount_residual', 'invoice_date', 'payment_state' ],
                    'limit'  => 100,
                    'order'  => 'invoice_date desc',
                ],
            ] ) ?: [];

            $out = [];
            foreach ( $rows as $r ) {
                $residual = (float) ( $r['amount_residual'] ?? 0 );
                $out[] = [
                    'name'    => $r['name'] ?? '',
                    'partner' => ak_mobile_partner_name( $r['partner_id'] ?? null ),
                    'amount'  => round( (float) ( $r['amount_total'] ?? 0 ), 2 ),
                    'date'    => $r['invoice_date'] ?? '',
                    'paid'    => ( $residual <= 0.009 ),
                    'status'  => ( $residual <= 0.009 ) ? 'مدفوعة' : 'متبقّي ' . number_format( $residual, 0 ),
                ];
            }
            return $out;
        },
    ] );

    /* ④ العملاء */
    register_rest_route( 'ak/v1', '/clients', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function () {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'فشل الاتصال بأودو', [ 'status' => 502 ] );

            $rows = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
                'res.partner', 'search_read',
                [ [ [ 'customer_rank', '>', 0 ] ] ],
                [
                    'fields' => ak_mobile_odoo_existing_fields( 'res.partner', $uid, [ 'name', 'phone', 'city', 'is_company' ] ),
                    'limit'  => 200,
                    'order'  => 'name asc',
                ],
            ] ) ?: [];

            $out = [];
            foreach ( $rows as $r ) {
                $type = ! empty( $r['is_company'] ) ? 'شركة' : 'فرد';
                $city = ! empty( $r['city'] ) ? ' · ' . $r['city'] : '';
                $out[] = [
                    'name'     => $r['name'] ?? '',
                    'sub'      => $type . $city,
                    'initials' => ak_mobile_initials( $r['name'] ?? '' ),
                    'phone'    => $r['phone'] ?: '',
                ];
            }
            return $out;
        },
    ] );

    /* ⑤ ديون العملاء — مطابقة لصفحة الموقع (مبيعات + فواتير لكل عميل) */
    register_rest_route( 'ak/v1', '/contacts', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'Odoo connection failed', [ 'status' => 502 ] );

            $search = sanitize_text_field( $req['search'] ?? '' );
            $domain = [];
            if ( $search !== '' ) {
                $domain = [ '|', '|', [ 'name', 'ilike', $search ], [ 'email', 'ilike', $search ], [ 'phone', 'ilike', $search ] ];
            }

            $rows = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY, 'res.partner', 'search_read', [ $domain ],
                [
                    'fields'  => ak_mobile_odoo_existing_fields( 'res.partner', $uid, [ 'id', 'name', 'email', 'phone', 'city', 'company_name', 'is_company', 'customer_rank', 'supplier_rank' ] ),
                    'limit'   => 200,
                    'order'   => 'name asc',
                    'context' => [ 'active_test' => false ],
                ],
            ] ) ?: [];

            $out = [];
            foreach ( $rows as $r ) {
                $phone = ! empty( $r['phone'] ) ? $r['phone'] : '';
                $kind = ! empty( $r['is_company'] ) ? 'Company' : 'Person';
                $out[] = [
                    'id'            => (int) ( $r['id'] ?? 0 ),
                    'name'          => ak_mobile_safe_text( $r['name'] ?? '' ),
                    'sub'           => $kind . ( ! empty( $r['city'] ) ? ' - ' . ak_mobile_safe_text( $r['city'] ) : '' ),
                    'initials'      => ak_mobile_initials( $r['name'] ?? '' ),
                    'phone'         => ak_mobile_safe_text( $phone ),
                    'email'         => ak_mobile_safe_text( $r['email'] ?? '' ),
                    'city'          => ak_mobile_safe_text( $r['city'] ?? '' ),
                    'company_name'  => ak_mobile_safe_text( $r['company_name'] ?? '' ),
                    'is_company'    => (bool) ( $r['is_company'] ?? false ),
                    'customer_rank' => (int) ( $r['customer_rank'] ?? 0 ),
                    'supplier_rank' => (int) ( $r['supplier_rank'] ?? 0 ),
                ];
            }
            return $out;
        },
    ] );

    register_rest_route( 'ak/v1', '/documents', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'Odoo connection failed', [ 'status' => 502 ] );

            $folder_id = (int) ( $req['folder_id'] ?? 0 );
            $search = sanitize_text_field( $req['search'] ?? '' );
            $fields = [ 'id', 'name', 'type', 'mimetype', 'file_size', 'folder_id' ];

            if ( $search !== '' ) {
                $folder_domain = [ [ 'type', '=', 'folder' ], [ 'name', 'ilike', $search ] ];
                $file_domain = [ [ 'type', '=', 'binary' ], [ 'name', 'ilike', $search ] ];
            } else {
                $parent = $folder_id ? [ [ 'folder_id', '=', $folder_id ] ] : [ [ 'folder_id', '=', false ] ];
                $folder_domain = array_merge( [ [ 'type', '=', 'folder' ] ], $parent );
                $file_domain = array_merge( [ [ 'type', '=', 'binary' ] ], $parent );
            }

            $folders = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY, 'documents.document', 'search_read',
                [ $folder_domain ],
                [ 'fields' => $fields, 'limit' => 200, 'order' => 'name asc' ],
            ] ) ?: [];

            $files = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY, 'documents.document', 'search_read',
                [ $file_domain ],
                [ 'fields' => $fields, 'limit' => 300, 'order' => 'name asc' ],
            ] ) ?: [];

            return [
                'folder_id'  => $folder_id,
                'search'     => $search,
                'breadcrumb' => $search === '' ? ak_mobile_docs_breadcrumb( $uid, $folder_id ) : [],
                'folders'    => array_map( 'ak_mobile_doc_item', is_array( $folders ) ? $folders : [] ),
                'files'      => array_map( 'ak_mobile_doc_item', is_array( $files ) ? $files : [] ),
            ];
        },
    ] );

    register_rest_route( 'ak/v1', '/documents/file', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'args'                => [ 'id' => [ 'required' => true ] ],
        'callback'            => function ( $req ) {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'Odoo connection failed', [ 'status' => 502 ] );
            $id = (int) $req['id'];
            if ( ! $id ) return new WP_Error( 'bad', 'Invalid document id', [ 'status' => 400 ] );

            $rows = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY, 'documents.document', 'read',
                [ [ $id ] ],
                [ 'fields' => [ 'id', 'name', 'mimetype', 'file_size', 'datas' ] ],
            ] );
            if ( empty( $rows ) || ! is_array( $rows ) ) return new WP_Error( 'notfound', 'Document not found', [ 'status' => 404 ] );
            $doc = $rows[0];
            return [
                'id'       => (int) ( $doc['id'] ?? $id ),
                'name'     => ak_mobile_safe_text( $doc['name'] ?? '' ),
                'mimetype' => ak_mobile_safe_text( $doc['mimetype'] ?? 'application/octet-stream' ),
                'size'     => (int) ( $doc['file_size'] ?? 0 ),
                'data'     => ak_mobile_safe_text( $doc['datas'] ?? '' ),
            ];
        },
    ] );

    register_rest_route( 'ak/v1', '/cases', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'Odoo connection failed', [ 'status' => 502 ] );

            $search = sanitize_text_field( $req['search'] ?? '' );
            $domain = [];
            if ( $search !== '' ) {
                $domain = [
                    '|',
                    [ 'name', 'ilike', $search ],
                    [ 'partner_id.name', 'ilike', $search ],
                ];
            }

            $rows = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
                'sale.order', 'search_read',
                [ $domain ],
                [
                    'fields' => [ 'id', 'name', 'partner_id', 'state', 'date_order', 'amount_total' ],
                    'limit'  => 100,
                    'order'  => 'date_order desc',
                ],
            ] ) ?: [];

            $ids = [];
            foreach ( $rows as $r ) {
                if ( ! empty( $r['id'] ) ) $ids[] = (int) $r['id'];
            }

            $counts = [];
            if ( $ids ) {
                $atts = ak_odoo_rpc( 'object', 'execute_kw', [
                    AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
                    'ir.attachment', 'search_read',
                    [ [ [ 'res_model', '=', 'sale.order' ], [ 'res_id', 'in', $ids ] ] ],
                    [ 'fields' => [ 'id', 'res_id' ], 'limit' => 5000 ],
                ] ) ?: [];
                if ( is_array( $atts ) ) {
                    foreach ( $atts as $a ) {
                        $rid = (int) ( $a['res_id'] ?? 0 );
                        if ( $rid ) $counts[ $rid ] = ( $counts[ $rid ] ?? 0 ) + 1;
                    }
                }
            }

            $out = [];
            foreach ( $rows as $r ) {
                $id = (int) ( $r['id'] ?? 0 );
                $out[] = [
                    'id'                => $id,
                    'name'              => ak_mobile_safe_text( $r['name'] ?? '' ),
                    'partner'           => ak_mobile_partner_name( $r['partner_id'] ?? null ),
                    'state'             => ak_mobile_safe_text( $r['state'] ?? '' ),
                    'date_order'        => ! empty( $r['date_order'] ) ? substr( $r['date_order'], 0, 10 ) : '',
                    'amount_total'      => round( (float) ( $r['amount_total'] ?? 0 ), 2 ),
                    'attachments_count' => (int) ( $counts[ $id ] ?? 0 ),
                ];
            }

            return $out;
        },
    ] );

    register_rest_route( 'ak/v1', '/cases/attachments', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'args'                => [ 'quote_id' => [ 'required' => true ] ],
        'callback'            => function ( $req ) {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'Odoo connection failed', [ 'status' => 502 ] );
            $quote_id = (int) $req['quote_id'];
            if ( ! $quote_id ) return new WP_Error( 'bad', 'Invalid quote id', [ 'status' => 400 ] );

            $rows = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
                'ir.attachment', 'search_read',
                [ [ [ 'res_model', '=', 'sale.order' ], [ 'res_id', '=', $quote_id ] ] ],
                [ 'fields' => [ 'id', 'name', 'mimetype', 'file_size', 'create_date' ], 'limit' => 500, 'order' => 'create_date desc' ],
            ] ) ?: [];

            $out = [];
            foreach ( is_array( $rows ) ? $rows : [] as $r ) {
                $out[] = [
                    'id'          => (int) ( $r['id'] ?? 0 ),
                    'name'        => ak_mobile_safe_text( $r['name'] ?? '' ),
                    'mimetype'    => ak_mobile_safe_text( $r['mimetype'] ?? 'application/octet-stream' ),
                    'file_size'   => (int) ( $r['file_size'] ?? 0 ),
                    'create_date' => ! empty( $r['create_date'] ) ? substr( $r['create_date'], 0, 10 ) : '',
                ];
            }

            return $out;
        },
    ] );

    register_rest_route( 'ak/v1', '/cases/file', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'args'                => [ 'id' => [ 'required' => true ] ],
        'callback'            => function ( $req ) {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'Odoo connection failed', [ 'status' => 502 ] );
            $id = (int) $req['id'];
            if ( ! $id ) return new WP_Error( 'bad', 'Invalid attachment id', [ 'status' => 400 ] );

            $rows = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
                'ir.attachment', 'read',
                [ [ $id ] ],
                [ 'fields' => [ 'id', 'name', 'mimetype', 'file_size', 'datas' ] ],
            ] );
            if ( empty( $rows ) || ! is_array( $rows ) ) return new WP_Error( 'notfound', 'Attachment not found', [ 'status' => 404 ] );
            $att = $rows[0];

            return [
                'id'       => (int) ( $att['id'] ?? $id ),
                'name'     => ak_mobile_safe_text( $att['name'] ?? '' ),
                'mimetype' => ak_mobile_safe_text( $att['mimetype'] ?? 'application/octet-stream' ),
                'size'     => (int) ( $att['file_size'] ?? 0 ),
                'data'     => ak_mobile_safe_text( $att['datas'] ?? '' ),
            ];
        },
    ] );

    register_rest_route( 'ak/v1', '/reception', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'Odoo connection failed', [ 'status' => 502 ] );

            $search = sanitize_text_field( $req->get_param( 'search' ) ?? '' );
            $filter = sanitize_text_field( $req->get_param( 'filter' ) ?? 'today' );
            $state  = sanitize_text_field( $req->get_param( 'state' ) ?? 'all' );
            $limit  = min( 100, max( 1, (int) ( $req->get_param( 'limit' ) ?? 50 ) ) );

            $domain = [];
            try {
                $tz  = new DateTimeZone( wp_timezone_string() ?: 'Asia/Riyadh' );
                $utc = new DateTimeZone( 'UTC' );
                $now = new DateTime( 'now', $tz );

                if ( 'today' === $filter ) {
                    $day = $now->format( 'Y-m-d' );
                    $s = ( new DateTime( $day . ' 00:00:00', $tz ) )->setTimezone( $utc );
                    $e = ( new DateTime( $day . ' 23:59:59', $tz ) )->setTimezone( $utc );
                    $domain[] = [ 'check_in', '>=', $s->format( 'Y-m-d H:i:s' ) ];
                    $domain[] = [ 'check_in', '<=', $e->format( 'Y-m-d H:i:s' ) ];
                } elseif ( 'week' === $filter ) {
                    $monday = clone $now;
                    $monday->modify( 'monday this week' );
                    $s = ( new DateTime( $monday->format( 'Y-m-d' ) . ' 00:00:00', $tz ) )->setTimezone( $utc );
                    $domain[] = [ 'check_in', '>=', $s->format( 'Y-m-d H:i:s' ) ];
                }
            } catch ( Exception $ex ) {}

            if ( $state && 'all' !== $state ) {
                $domain[] = [ 'state', '=', $state ];
            }
            if ( '' !== $search ) {
                $domain = array_merge( $domain, [
                    '|',
                    '|',
                    [ 'name', 'ilike', $search ],
                    [ 'phone', 'ilike', $search ],
                    [ 'host_ids.name', 'ilike', $search ],
                ] );
            }

            $fields = ak_mobile_odoo_existing_fields( 'frontdesk.visitor', $uid, [
                'id', 'name', 'phone', 'email', 'check_in', 'check_out', 'host_ids', 'state', 'station_id',
            ] );

            $rows = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
                'frontdesk.visitor', 'search_read',
                [ $domain ],
                [ 'fields' => $fields, 'limit' => $limit, 'order' => 'check_in desc' ],
            ] );

            if ( ! is_array( $rows ) ) return [];

            $host_ids = [];
            foreach ( $rows as $row ) {
                foreach ( (array) ( $row['host_ids'] ?? [] ) as $host_id ) {
                    $host_ids[] = (int) $host_id;
                }
            }

            $hosts_map = [];
            $host_ids = array_values( array_unique( array_filter( $host_ids ) ) );
            if ( $host_ids ) {
                $host_rows = ak_odoo_rpc( 'object', 'execute_kw', [
                    AK_ODOO_DB, $uid, AK_ODOO_API_KEY,
                    'hr.employee', 'read',
                    [ $host_ids ],
                    [ 'fields' => [ 'id', 'name' ] ],
                ] );
                foreach ( is_array( $host_rows ) ? $host_rows : [] as $host ) {
                    $hosts_map[ (int) ( $host['id'] ?? 0 ) ] = ak_mobile_safe_text( $host['name'] ?? '' );
                }
            }

            $labels = [
                'checked_in'  => 'داخل الآن',
                'checked_out' => 'غادر',
                'planned'     => 'موعد',
                'cancelled'   => 'ملغي',
            ];

            $out = [];
            foreach ( $rows as $row ) {
                $names = [];
                foreach ( (array) ( $row['host_ids'] ?? [] ) as $host_id ) {
                    $host_id = (int) $host_id;
                    if ( isset( $hosts_map[ $host_id ] ) && '' !== $hosts_map[ $host_id ] ) {
                        $names[] = $hosts_map[ $host_id ];
                    }
                }

                $station = $row['station_id'] ?? null;
                $state_value = ak_mobile_safe_text( $row['state'] ?? '' );

                $out[] = [
                    'id'        => (int) ( $row['id'] ?? 0 ),
                    'name'      => ak_mobile_safe_text( $row['name'] ?? '' ),
                    'phone'     => ak_mobile_safe_text( $row['phone'] ?? '' ),
                    'email'     => ak_mobile_safe_text( $row['email'] ?? '' ),
                    'check_in'  => ak_mobile_safe_text( $row['check_in'] ?? '' ),
                    'check_out' => ak_mobile_safe_text( $row['check_out'] ?? '' ),
                    'state'     => $state_value,
                    'status'    => $labels[ $state_value ] ?? $state_value,
                    'station'   => is_array( $station ) ? ak_mobile_safe_text( $station[1] ?? '' ) : '',
                    'hosts'     => $names,
                    'host'      => implode( '، ', $names ),
                ];
            }

            return $out;
        },
    ] );

    register_rest_route( 'ak/v1', '/jobs', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function () {
            $posts = get_posts( [
                'post_type' => 'job_listing', 'post_status' => [ 'publish', 'draft' ],
                'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC',
            ] );
            return array_map( function ( $p ) {
                return [
                    'id' => (int) $p->ID, 'title' => get_the_title( $p ), 'status' => $p->post_status,
                    'date' => get_the_date( 'Y-m-d', $p ),
                    'company' => (string) get_post_meta( $p->ID, '_company_name', true ),
                    'expires' => (string) get_post_meta( $p->ID, '_job_expires', true ),
                ];
            }, $posts );
        },
    ] );

    register_rest_route( 'ak/v1', '/applications', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function () {
            $posts = get_posts( [
                'post_type' => 'job_application', 'post_status' => [ 'private', 'publish' ],
                'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC',
            ] );
            return array_map( function ( $p ) {
                return [
                    'id' => (int) $p->ID, 'name' => get_the_title( $p ), 'date' => get_the_date( 'Y-m-d', $p ),
                    'job' => (string) get_post_meta( $p->ID, 'job_title', true ),
                    'email' => (string) get_post_meta( $p->ID, 'email', true ),
                    'phone' => (string) get_post_meta( $p->ID, 'phone', true ),
                ];
            }, $posts );
        },
    ] );

    register_rest_route( 'ak/v1', '/sales', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function () {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'Odoo connection failed', [ 'status' => 502 ] );

            $rows = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY, 'sale.order', 'search_read',
                [ [] ],
                [ 'fields' => [ 'id', 'name', 'partner_id', 'state', 'date_order', 'amount_total' ], 'limit' => 100, 'order' => 'date_order desc' ],
            ] ) ?: [];

            $out = [];
            foreach ( $rows as $r ) {
                $out[] = [
                    'id' => (int) ( $r['id'] ?? 0 ), 'name' => ak_mobile_safe_text( $r['name'] ?? '' ),
                    'partner' => ak_mobile_partner_name( $r['partner_id'] ?? null ),
                    'state' => ak_mobile_safe_text( $r['state'] ?? '' ),
                    'date' => ! empty( $r['date_order'] ) ? substr( $r['date_order'], 0, 10 ) : '',
                    'amount' => round( (float) ( $r['amount_total'] ?? 0 ), 2 ),
                ];
            }
            return $out;
        },
    ] );

    register_rest_route( 'ak/v1', '/receivables', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function () {
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'فشل الاتصال بأودو', [ 'status' => 502 ] );

            /* المبيعات المؤكدة */
            $sales = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY, 'sale.order', 'search_read',
                [ [ [ 'state', 'in', [ 'sale', 'done' ] ] ] ],
                [ 'fields' => [ 'partner_id', 'amount_total' ], 'limit' => 9999 ],
            ] ) ?: [];

            /* فواتير العملاء */
            $invoices = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY, 'account.move', 'search_read',
                [ [ [ 'move_type', '=', 'out_invoice' ] ] ],
                [ 'fields' => [ 'partner_id', 'amount_total', 'state', 'payment_state', 'date' ], 'limit' => 9999 ],
            ] ) ?: [];

            $cust = [];
            foreach ( $sales as $s ) {
                $p = $s['partner_id']; if ( ! is_array( $p ) ) continue; $id = $p[0];
                if ( ! isset( $cust[ $id ] ) ) $cust[ $id ] = [ 'id' => $id, 'name' => $p[1], 'sales' => 0, 'paid' => 0, 'phone' => '', 'last' => '' ];
                $cust[ $id ]['sales'] += $s['amount_total'];
            }
            foreach ( $invoices as $inv ) {
                $p = $inv['partner_id']; if ( ! is_array( $p ) ) continue; $id = $p[0];
                if ( ! isset( $cust[ $id ] ) ) $cust[ $id ] = [ 'id' => $id, 'name' => $p[1], 'sales' => 0, 'paid' => 0, 'phone' => '', 'last' => '' ];
                $state = $inv['state'] ?? ''; $ps = $inv['payment_state'] ?? 'not_paid';
                $settled = ( $state === 'posted' ) || in_array( $ps, [ 'paid', 'in_payment', 'partial' ], true );
                if ( $settled ) {
                    $cust[ $id ]['paid'] += $inv['amount_total'];
                    $d = $inv['date'] ?? '';
                    if ( $d && $d > $cust[ $id ]['last'] ) $cust[ $id ]['last'] = $d;
                }
            }

            /* أرقام الهواتف للتحصيل */
            $ids = array_map( 'intval', array_keys( $cust ) );
            if ( $ids ) {
                $pi = ak_odoo_rpc( 'object', 'execute_kw', [
                    AK_ODOO_DB, $uid, AK_ODOO_API_KEY, 'res.partner', 'read',
                    [ $ids ], [ 'fields' => ak_mobile_odoo_existing_fields( 'res.partner', $uid, [ 'id', 'phone' ] ) ],
                ] );
                if ( is_array( $pi ) ) foreach ( $pi as $x ) {
                    $id = (int) $x['id'];
                    if ( isset( $cust[ $id ] ) ) $cust[ $id ]['phone'] = ( ! empty( $x['phone'] ) ? $x['phone'] : '' );
                }
            }

            /* تصنيفات التحصيل المحفوظة */
            $tags = function_exists( 'ak_recv_get_all_tags' ) ? ak_recv_get_all_tags() : [];

            $out = []; $tot_debt = 0; $tot_paid = 0; $tot_sales = 0;
            foreach ( $cust as $c ) {
                $sv = $c['sales']; $paid = $c['paid']; $rem = max( 0, $sv - $paid );
                if ( $paid > $sv ) $sv = $paid;
                if ( $sv > 50000000 ) continue;
                $tag = isset( $tags[ $c['id'] ] ) ? $tags[ $c['id'] ]['tag'] : '';
                $tot_debt += $rem; $tot_paid += $paid; $tot_sales += ( $paid + $rem );
                $out[] = [
                    'id'        => (int) $c['id'],
                    'name'      => $c['name'],
                    'sales'     => round( $paid + $rem, 2 ),
                    'paid'      => round( $paid, 2 ),
                    'remaining' => round( $rem, 2 ),
                    'phone'     => $c['phone'],
                    'last'      => $c['last'] ? substr( $c['last'], 0, 10 ) : '',
                    'tag'       => $tag,
                    'tag_label' => ak_recv_tag_label( $tag ),
                ];
            }
            usort( $out, function ( $a, $b ) {
                if ( $a['remaining'] > 0 && $b['remaining'] <= 0 ) return -1;
                if ( $a['remaining'] <= 0 && $b['remaining'] > 0 ) return 1;
                return $b['remaining'] <=> $a['remaining'];
            } );

            return [
                'stats'     => [
                    'sales' => round( $tot_sales, 2 ),
                    'paid'  => round( $tot_paid, 2 ),
                    'debt'  => round( $tot_debt, 2 ),
                    'count' => count( $out ),
                ],
                'customers' => array_slice( $out, 0, 300 ),
            ];
        },
    ] );

    /* ⑥ تفاصيل عميل واحد — فواتيره وتواريخ سداده (مطابق للموقع) */
    register_rest_route( 'ak/v1', '/receivables/customer', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'args'                => [ 'id' => [ 'required' => true ] ],
        'callback'            => function ( $req ) {
            $cid = (int) $req['id'];
            if ( ! $cid ) return new WP_Error( 'bad', 'معرّف غير صالح', [ 'status' => 400 ] );
            $uid = ak_odoo_uid();
            if ( ! $uid ) return new WP_Error( 'odoo', 'فشل الاتصال بأودو', [ 'status' => 502 ] );

            $partners = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY, 'res.partner', 'read', [ [ $cid ] ],
                [ 'fields' => ak_mobile_odoo_existing_fields( 'res.partner', $uid, [ 'name', 'email', 'phone', 'city', 'parent_id', 'child_ids' ] ) ],
            ] );
            if ( empty( $partners ) || ! is_array( $partners ) )
                return new WP_Error( 'notfound', 'تعذّر جلب العميل', [ 'status' => 404 ] );
            $partner = $partners[0];

            $related = [ $cid ];
            if ( ! empty( $partner['parent_id'] ) && is_array( $partner['parent_id'] ) ) $related[] = (int) $partner['parent_id'][0];
            if ( ! empty( $partner['child_ids'] ) && is_array( $partner['child_ids'] ) ) foreach ( $partner['child_ids'] as $ch ) $related[] = (int) $ch;
            $related = array_values( array_unique( $related ) );

            $invoices = ak_odoo_rpc( 'object', 'execute_kw', [
                AK_ODOO_DB, $uid, AK_ODOO_API_KEY, 'account.move', 'search_read',
                [ [ [ 'partner_id', 'in', $related ], [ 'move_type', '=', 'out_invoice' ] ] ],
                [ 'fields' => [ 'name', 'amount_total', 'amount_residual', 'state', 'payment_state', 'date' ], 'order' => 'date desc', 'limit' => 500 ],
            ] ) ?: [];

            $list = []; $tot = 0; $paidT = 0; $remT = 0;
            foreach ( $invoices as $inv ) {
                $state = $inv['state'] ?? ''; $ps = $inv['payment_state'] ?? 'not_paid';
                $settled = ( $state === 'posted' ) || in_array( $ps, [ 'paid', 'in_payment', 'partial' ], true );
                $total = (float) ( $inv['amount_total'] ?? 0 );
                $paid = $settled ? $total : 0; $rem = $settled ? 0 : $total;
                if ( $state === 'draft' || $state === 'cancel' ) { $paid = 0; $rem = 0; }
                $tot += $total; $paidT += $paid; $remT += $rem;
                $list[] = [
                    'name'      => $inv['name'] ?: '—',
                    'date'      => ! empty( $inv['date'] ) ? substr( $inv['date'], 0, 10 ) : '',
                    'total'     => round( $total, 2 ),
                    'paid'      => round( $paid, 2 ),
                    'remaining' => round( $rem, 2 ),
                    'settled'   => $settled,
                    'status'    => ( $state === 'draft' ? 'مسودة' : ( $settled ? 'مسددة' : 'غير مسددة' ) ),
                ];
            }
            $phone = ! empty( $partner['phone'] ) ? $partner['phone'] : '';
            return [
                'name'     => (string) ( $partner['name']  ?: '' ),
                'phone'    => (string) $phone,
                'email'    => (string) ( $partner['email'] ?: '' ),
                'city'     => (string) ( $partner['city']  ?: '' ),
                'summary'  => [ 'total' => round( $tot, 2 ), 'paid' => round( $paidT, 2 ), 'remaining' => round( $remT, 2 ) ],
                'invoices' => $list,
            ];
        },
    ] );

    /* ⑦ أسلوب المحامي — إعادة صياغة نص بأسلوب قانوني (لإضافة Word) */
    register_rest_route( 'ak/v1', '/style', [
        'methods'             => 'POST',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $text = trim( (string) $req->get_param( 'text' ) );
            if ( $text === '' ) return new WP_Error( 'empty', 'لا يوجد نص', [ 'status' => 400 ] );
            $key = get_option( 'ak_gemini_api_key', '' );
            if ( ! $key ) return new WP_Error( 'noai', 'مفتاح الذكاء غير مضبوط', [ 'status' => 500 ] );

            /* دليل الأسلوب المخزّن مسبقاً فقط (بدون بناء) */
            $guide = '';
            $cache = get_option( 'ak_editor_style_guide_cache' );
            if ( is_array( $cache ) && ! empty( $cache['guide'] ) ) $guide = (string) $cache['guide'];

            $prompt = ( $guide !== '' ? "دليل أسلوب المحامي (التزم به في الصياغة):\n" . $guide . "\n\n" : "" )
                . "أعد صياغة النص التالي بأسلوب قانوني عربي رصين واحترافي، مع الحفاظ التام على المعنى والوقائع والأرقام والأسماء. "
                . "أخرج النص المعاد صياغته فقط كنص عادي، بدون أي مقدمات أو عناوين أو رموز:\n\n" . $text;

            /* نداء Gemini مباشر ومحدّد بمهلة — يفشل بسرعة بدل التعليق */
            $url  = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=' . rawurlencode( $key );
            $resp = wp_remote_post( $url, [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'contents' => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ] ] ),
                'timeout' => 25,
            ] );
            if ( is_wp_error( $resp ) ) return new WP_Error( 'timeout', 'تأخّر الذكاء في الرد — قلّص النص أو حاول مرة أخرى', [ 'status' => 504 ] );
            $data = json_decode( wp_remote_retrieve_body( $resp ), true );
            $out  = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if ( trim( (string) $out ) === '' ) return new WP_Error( 'fail', 'تعذّر الحصول على نتيجة من الذكاء', [ 'status' => 502 ] );
            return [ 'text' => trim( $out ) ];
        },
    ] );

    /* ⑧ مساعد القضية — محادثة متعددة الوسائط (مرفقات) تكتب المستندات القانونية بأسلوب المكتب */
    register_rest_route( 'ak/v1', '/case', [
        'methods'             => 'POST',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $messages    = $req->get_param( 'messages' );
            $attachments = $req->get_param( 'attachments' );
            $mode        = sanitize_text_field( (string) $req->get_param( 'mode' ) );
            $doc_type    = sanitize_text_field( (string) $req->get_param( 'doc_type' ) );
            $model       = sanitize_text_field( (string) $req->get_param( 'model' ) );
            if ( ! is_array( $messages ) )    $messages = [];
            if ( ! is_array( $attachments ) ) $attachments = [];
            if ( empty( $messages ) )         return new WP_Error( 'empty', 'لا توجد رسالة', [ 'status' => 400 ] );

            /* فحص تشخيصي: اكتب «تشخيص المرفقات» مع إرفاق ملف لمعرفة هل وصل للسيرفر */
            $lastText = '';
            for ( $z = count( $messages ) - 1; $z >= 0; $z-- ) {
                if ( ( $messages[ $z ]['role'] ?? 'user' ) !== 'model' ) { $lastText = trim( (string) ( $messages[ $z ]['text'] ?? '' ) ); break; }
            }
            if ( $lastText === 'تشخيص المرفقات' ) {
                $info = [];
                foreach ( $attachments as $a ) {
                    $d = (string) ( $a['data'] ?? '' );
                    $info[] = [ 'mime' => (string) ( $a['mime'] ?? '' ), 'b64' => strlen( $d ), 'bytes' => strlen( (string) base64_decode( $d, true ) ) ];
                }
                return [ 'reply' => 'تشخيص ← عدد المرفقات: ' . count( $attachments ) . "\n" . wp_json_encode( $info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ];
            }

            /* معالجة بالخلفية: ننشئ مهمة، نطلق العامل دون انتظار، ونرجّع المعرّف فوراً (يتفادى مهلة الاتصال للمرفقات الثقيلة) */
            $job_id = wp_generate_password( 24, false );
            $secret = wp_generate_password( 20, false );
            set_transient( 'ak_job_' . $job_id, [
                'status'      => 'pending',
                'secret'      => $secret,
                'messages'    => $messages,
                'attachments' => $attachments,
                'mode'        => $mode,
                'doc_type'    => $doc_type,
                'model'       => $model,
            ], 2 * HOUR_IN_SECONDS );
            wp_remote_post( rest_url( 'ak/v1/case/run' ), [
                'blocking'  => false,
                'timeout'   => 0.01,
                'sslverify' => false,
                'body'      => [ 'job' => $job_id, 'secret' => $secret ],
            ] );
            return [ 'job_id' => $job_id ];
        },
    ] );

    /* تحضير: يبني التوجيه + يسترجع المكتبة، ويرجّع المفتاح للعميل ليكلّم Gemini مباشرةً (بثّ حقيقي بلا جدار السيرفر) */
    register_rest_route( 'ak/v1', '/prep', [
        'methods'             => 'POST',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $messages = $req->get_param( 'messages' );
            $doc_type = sanitize_text_field( (string) $req->get_param( 'doc_type' ) );
            $model    = sanitize_text_field( (string) $req->get_param( 'model' ) );
            if ( ! is_array( $messages ) ) $messages = [];
            if ( empty( $messages ) ) return new WP_Error( 'empty', 'لا توجد رسالة', [ 'status' => 400 ] );
            $allowed = [ 'gemini-2.5-flash', 'gemini-3.5-flash', 'gemini-2.5-pro', 'gemini-3.1-pro-preview' ];
            if ( ! in_array( $model, $allowed, true ) ) $model = 'gemini-2.5-pro';
            $key = get_option( 'ak_gemini_api_key', '' );
            if ( ! $key ) return new WP_Error( 'nokey', 'مفتاح الذكاء غير مضبوط', [ 'status' => 500 ] );
            return [
                'key'             => $key,
                'system'          => ak_build_sys( $messages, $doc_type ),
                'model'           => $model,
                'temperature'     => 0.3,
                'maxOutputTokens' => 16384,
            ];
        },
    ] );

    /* عامل المعالجة بالخلفية: ينفّذ نداء Gemini فعلياً ويخزّن النتيجة (لا انتظار من المتصفح) */
    register_rest_route( 'ak/v1', '/case/run', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function ( $req ) {
            $id     = sanitize_text_field( (string) $req->get_param( 'job' ) );
            $secret = (string) $req->get_param( 'secret' );
            $job    = get_transient( 'ak_job_' . $id );
            if ( ! is_array( $job ) || ( $job['secret'] ?? '' ) !== $secret ) return [ 'ok' => false ];
            if ( ( $job['status'] ?? '' ) !== 'pending' ) return [ 'ok' => true ];
            @set_time_limit( 0 );
            @ignore_user_abort( true );
            $job['status'] = 'running';
            set_transient( 'ak_job_' . $id, $job, 2 * HOUR_IN_SECONDS );
            $r = ak_case_process( $job['messages'], $job['attachments'], $job['mode'], $job['doc_type'], $job['model'] ?? '' );
            if ( ! empty( $r['ok'] ) ) { $job['status'] = 'done'; $job['reply'] = (string) $r['reply']; }
            else { $job['status'] = 'error'; $job['error'] = (string) ( $r['error'] ?? 'خطأ غير معروف' ); }
            unset( $job['messages'], $job['attachments'] );
            set_transient( 'ak_job_' . $id, $job, 2 * HOUR_IN_SECONDS );
            return [ 'ok' => true ];
        },
    ] );

    /* نتيجة المهمة — يستعلم عنها المتصفح كل بضع ثوانٍ حتى تجهز */
    register_rest_route( 'ak/v1', '/case/result', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $id  = sanitize_text_field( (string) $req->get_param( 'job' ) );
            $job = get_transient( 'ak_job_' . $id );
            if ( ! is_array( $job ) ) return [ 'status' => 'unknown' ];
            $st = $job['status'] ?? 'pending';
            if ( $st === 'done' )  return [ 'status' => 'done',  'reply' => (string) ( $job['reply'] ?? '' ) ];
            if ( $st === 'error' ) return [ 'status' => 'error', 'error' => (string) ( $job['error'] ?? 'خطأ' ) ];
            return [ 'status' => $st ];
        },
    ] );

    /* مستكشف الأعطال: يختبر محركاً مباشرةً + اختبار الاسترجاع — لعزل سبب الفشل */
    register_rest_route( 'ak/v1', '/case/diag', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $key = get_option( 'ak_gemini_api_key', '' );
            if ( ! $key ) return [ 'ok' => false, 'error' => 'مفتاح الذكاء غير مضبوط' ];
            $model   = sanitize_text_field( (string) $req->get_param( 'model' ) );
            $allowed = [ 'gemini-2.5-flash', 'gemini-3.5-flash', 'gemini-2.5-pro', 'gemini-3.1-pro-preview' ];
            $valid   = in_array( $model, $allowed, true );
            if ( ! $valid ) $model = 'gemini-2.5-pro';

            /* (أ) اختبار توليد خام بالمحرك المطلوب */
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode( $key );
            $t0  = microtime( true );
            $resp = wp_remote_post( $url, [ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( [ 'contents' => [ [ 'parts' => [ [ 'text' => 'قل: تم' ] ] ] ] ] ), 'timeout' => 60 ] );
            $gen_ms = round( ( microtime( true ) - $t0 ) * 1000 );
            if ( is_wp_error( $resp ) ) { $gen = [ 'ok' => false, 'wp_error' => $resp->get_error_message() ]; }
            else {
                $code = wp_remote_retrieve_response_code( $resp );
                $d    = json_decode( wp_remote_retrieve_body( $resp ), true );
                $txt  = $d['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $gen  = [ 'ok' => ( trim( $txt ) !== '' ), 'http' => $code, 'reply' => mb_substr( $txt, 0, 60 ), 'api_error' => $d['error']['message'] ?? '' ];
            }

            /* (ب) اختبار الاسترجاع القضائي (هل يعلّق هنا؟) */
            $t1 = microtime( true );
            $ret = function_exists( 'ak_qadha_retrieve' ) ? ak_qadha_retrieve( 'اختبار نزاع عمالي', 2, 2, 800 ) : '(دالة غير موجودة)';
            $ret_ms = round( ( microtime( true ) - $t1 ) * 1000 );

            return [
                'model_requested'   => $req->get_param( 'model' ),
                'model_used'        => $model,
                'model_valid'       => $valid,
                'generation'        => $gen,
                'generation_ms'     => $gen_ms,
                'retrieval_ok'      => ( is_string( $ret ) && $ret !== '' ),
                'retrieval_chars'   => is_string( $ret ) ? mb_strlen( $ret ) : 0,
                'retrieval_ms'      => $ret_ms,
                'snippet_has_model' => true,
            ];
        },
    ] );

    /* استيراد فهرس المكتبة القضائية (مقاطع + متجهات + مراكز الكتب) — دفعات */
    register_rest_route( 'ak/v1', '/qadha/import', [
        'methods'             => 'POST',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            global $wpdb;
            $t = $wpdb->prefix . 'ak_qadha_chunks';
            ak_qadha_create_table();
            if ( $req->get_param( 'reset' ) ) $wpdb->query( "TRUNCATE $t" );
            $cent = $req->get_param( 'centroids' );
            if ( is_array( $cent ) ) update_option( 'ak_qadha_books', $cent, false );
            $rows = $req->get_param( 'chunks' );
            $ins  = 0;
            if ( is_array( $rows ) ) foreach ( $rows as $r ) {
                $wpdb->replace( $t, [
                    'id'   => (int) ( $r['id'] ?? 0 ),
                    'b'    => (int) ( $r['b'] ?? 0 ),
                    'body' => (string) ( $r['text'] ?? '' ),
                    'vec'  => (string) ( $r['v'] ?? '' ),
                ] );
                $ins++;
            }
            return [ 'ok' => true, 'inserted' => $ins, 'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ) ];
        },
    ] );

    /* اختبار/معاينة البحث القضائي الدلالي */
    register_rest_route( 'ak/v1', '/qadha/search', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $q = (string) $req->get_param( 'q' );
            if ( trim( $q ) === '' ) return [ 'error' => 'no query' ];
            return [ 'context' => ak_qadha_retrieve( $q ) ];
        },
    ] );

    /* ⑨ بدء رفع ملف كبير مباشرة لخوادم Gemini (Files API) — يرجّع رابط رفع آمن */
    register_rest_route( 'ak/v1', '/upload/start', [
        'methods'             => 'POST',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $name = sanitize_text_field( (string) $req->get_param( 'name' ) );
            $mime = sanitize_text_field( (string) $req->get_param( 'mime' ) );
            $size = (int) $req->get_param( 'size' );
            $key  = get_option( 'ak_gemini_api_key', '' );
            if ( ! $key || ! $mime || $size <= 0 ) return new WP_Error( 'bad', 'بيانات الرفع ناقصة', [ 'status' => 400 ] );
            $resp = wp_remote_post( 'https://generativelanguage.googleapis.com/upload/v1beta/files?key=' . rawurlencode( $key ), [
                'headers' => [
                    'X-Goog-Upload-Protocol'            => 'resumable',
                    'X-Goog-Upload-Command'             => 'start',
                    'X-Goog-Upload-Header-Content-Length' => (string) $size,
                    'X-Goog-Upload-Header-Content-Type' => $mime,
                    'Content-Type'                      => 'application/json',
                ],
                'body'    => wp_json_encode( [ 'file' => [ 'display_name' => ( $name ?: 'file' ) ] ] ),
                'timeout' => 30,
            ] );
            if ( is_wp_error( $resp ) ) return new WP_Error( 'start', 'تعذّر بدء الرفع', [ 'status' => 502 ] );
            $url = wp_remote_retrieve_header( $resp, 'x-goog-upload-url' );
            if ( ! $url ) return new WP_Error( 'start', 'لم نحصل على رابط الرفع', [ 'status' => 502 ] );
            $tok = wp_generate_password( 24, false );
            set_transient( 'ak_up_' . $tok, $url, 2 * HOUR_IN_SECONDS );
            return [ 'upload_id' => $tok ];
        },
    ] );

    /* ⑨ب رفع الملف على أجزاء عبر السيرفر (يتفادى CORS، يتحمّل أي حجم) */
    register_rest_route( 'ak/v1', '/upload/chunk', [
        'methods'             => 'POST',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            $id     = sanitize_text_field( (string) $req->get_param( 'upload_id' ) );
            $offset = (int) $req->get_param( 'offset' );
            $last   = (bool) $req->get_param( 'last' );
            $data   = (string) $req->get_param( 'data' );
            $url    = get_transient( 'ak_up_' . $id );
            if ( ! $url ) return new WP_Error( 'noid', 'انتهت جلسة الرفع', [ 'status' => 400 ] );
            $bytes = base64_decode( $data, true );
            if ( $bytes === false ) return new WP_Error( 'bad', 'بيانات غير صالحة', [ 'status' => 400 ] );
            $cmd  = $last ? 'upload, finalize' : 'upload';
            $resp = wp_remote_post( $url, [
                'headers' => [ 'X-Goog-Upload-Offset' => (string) $offset, 'X-Goog-Upload-Command' => $cmd ],
                'body'    => $bytes,
                'timeout' => 90,
            ] );
            if ( is_wp_error( $resp ) ) return new WP_Error( 'chunk', 'تعذّر رفع الجزء', [ 'status' => 502 ] );
            if ( $last ) {
                $d    = json_decode( wp_remote_retrieve_body( $resp ), true );
                $file = $d['file'] ?? $d;
                delete_transient( 'ak_up_' . $id );
                return [ 'uri' => $file['uri'] ?? '', 'name' => $file['name'] ?? '', 'state' => $file['state'] ?? 'PROCESSING' ];
            }
            return [ 'ok' => true ];
        },
    ] );

    /* ⑩ حالة ملف Gemini (للتأكد أنه جاهز ACTIVE قبل الاستخدام، خصوصاً الفيديو) */
    register_rest_route( 'ak/v1', '/upload/status', [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'args'                => [ 'name' => [ 'required' => true ] ],
        'callback'            => function ( $req ) {
            $name = ltrim( (string) $req->get_param( 'name' ), '/' );
            $key  = get_option( 'ak_gemini_api_key', '' );
            if ( ! $name || ! $key ) return new WP_Error( 'bad', 'ناقص', [ 'status' => 400 ] );
            $resp = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/' . $name . '?key=' . rawurlencode( $key ), [ 'timeout' => 20 ] );
            if ( is_wp_error( $resp ) ) return new WP_Error( 'st', 'تعذّر', [ 'status' => 502 ] );
            $d = json_decode( wp_remote_retrieve_body( $resp ), true );
            return [ 'state' => $d['state'] ?? 'UNKNOWN', 'uri' => $d['uri'] ?? '' ];
        },
    ] );

} );

add_action( 'rest_api_init', function () {
    $contacts_route = [
        'methods'             => 'GET',
        'permission_callback' => 'ak_mobile_can',
        'callback'            => function ( $req ) {
            return ak_mobile_contacts_list( $req );
        },
    ];

    register_rest_route( 'ak/v1', '/contacts', $contacts_route, true );
    register_rest_route( 'ak/v1', '/clients', $contacts_route, true );
}, 20 );
