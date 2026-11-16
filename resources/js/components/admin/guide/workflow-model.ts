import type { Replacements } from '@/lib/i18n';

export type NodeId = 'source' | 'collect' | 'classify' | 'assess' | 'immediate' | 'digest' | 'webhook' | 'team' | 'admin';
export type Scenario = 'vendor' | 'package' | 'digest' | 'kev' | 'failure';
export type Question = 'recipients' | 'polling' | 'digest' | 'no-team' | 'retry';
export type OutputState = 'inactive' | 'new' | 'ready' | 'waiting' | 'queued' | 'sending' | 'sent' | 'skipped' | 'failed' | 'retry';
export type EdgeId = 'collect' | 'classify' | 'assess' | 'immediate' | 'digest' | 'send' | 'digest-send' | 'deliver' | 'news-admin' | 'source-failed' | 'retry';
type Translate = (key: string, replacements?: Replacements) => string;

export interface VendorNewsSource {
    key: 'php' | 'nodejs' | 'ruby';
    name: string;
    website_url: string;
    feed_url: string;
}

export interface Scene {
    node: NodeId;
    path?: EdgeId;
    packet: 'N01' | 'K01' | 'A01' | 'T01' | 'D01';
    title: string;
    caption: string;
    time: string;
    admin: OutputState;
    team: OutputState;
}

export interface WorkflowOptions {
    scenario: Scenario;
    hasTeam: boolean;
    retry: boolean;
    immediateSeverities: string[];
    collectionFrequency: string;
    digestTime: string;
    digestDays: string;
    timezone: string;
    noticeRelevantDays: number;
}

export const positions: Record<NodeId, [number, number]> = {
    source: [140, 100],
    collect: [140, 260],
    classify: [376, 260],
    assess: [600, 260],
    immediate: [830, 124],
    digest: [600, 446],
    webhook: [1010, 350],
    admin: [376, 540],
    team: [1010, 540],
};

export const routes: Record<EdgeId, { points: [number, number][]; line: string; label: string; labelAt: [number, number] }> = {
    collect: {
        points: [
            [140, 122],
            [140, 260],
        ],
        line: 'M140 174V222',
        label: 'đọc nguồn',
        labelAt: [155, 200],
    },
    classify: {
        points: [
            [140, 260],
            [376, 260],
        ],
        line: 'M178 260H338',
        label: 'chuẩn hoá',
        labelAt: [230, 245],
    },
    assess: {
        points: [
            [376, 260],
            [600, 260],
        ],
        line: 'M414 260H562',
        label: 'đối chiếu',
        labelAt: [463, 245],
    },
    immediate: {
        points: [
            [600, 260],
            [736, 260],
            [736, 124],
            [830, 124],
        ],
        line: 'M638 260H736V124H792',
        label: 'chọn nhánh',
        labelAt: [750, 233],
    },
    digest: {
        points: [
            [600, 260],
            [690, 260],
            [690, 396],
            [600, 396],
            [600, 446],
        ],
        line: 'M638 260H690V396H600V408',
        label: 'chờ lịch',
        labelAt: [701, 375],
    },
    send: {
        points: [
            [830, 124],
            [902, 124],
            [902, 350],
            [1010, 350],
        ],
        line: 'M868 124H902V350H972',
        label: 'vào queue',
        labelAt: [914, 266],
    },
    'digest-send': {
        points: [
            [600, 446],
            [918, 446],
            [918, 350],
            [1010, 350],
        ],
        line: 'M638 446H918V350H972',
        label: 'gom và gửi',
        labelAt: [753, 431],
    },
    deliver: {
        points: [
            [1010, 350],
            [1080, 350],
            [1080, 540],
            [1010, 540],
        ],
        line: 'M1048 350H1080V540H1048',
        label: 'POST',
        labelAt: [1053, 510],
    },
    'news-admin': {
        points: [
            [376, 260],
            [454, 260],
            [454, 492],
            [376, 492],
            [376, 540],
        ],
        line: 'M414 260H454V492H376V502',
        label: 'đầu ra riêng',
        labelAt: [468, 449],
    },
    'source-failed': {
        points: [
            [140, 260],
            [62, 260],
            [62, 540],
            [376, 540],
        ],
        line: 'M102 260H62V540H338',
        label: 'báo lỗi',
        labelAt: [228, 525],
    },
    retry: {
        points: [
            [1010, 350],
            [1088, 350],
            [1088, 270],
            [1010, 270],
            [1010, 350],
        ],
        line: 'M1048 350H1088V270H1010V312',
        label: 'thử lại khi lỗi',
        labelAt: [987, 248],
    },
};

export function pointOnRoute(points: [number, number][], progress: number): [number, number] {
    const lengths = points.slice(1).map(([x, y], i) => Math.hypot(x - points[i][0], y - points[i][1]));
    let remaining = Math.max(0, Math.min(progress, 1)) * lengths.reduce((total, length) => total + length, 0);
    for (let i = 0; i < lengths.length; i++) {
        if (remaining <= lengths[i]) {
            const ratio = remaining / lengths[i];
            return [points[i][0] + (points[i + 1][0] - points[i][0]) * ratio, points[i][1] + (points[i + 1][1] - points[i][1]) * ratio];
        }
        remaining -= lengths[i];
    }
    return points[points.length - 1];
}

export function buildScenes(options: WorkflowOptions, t: Translate): Scene[] {
    const { scenario, hasTeam, retry, immediateSeverities, collectionFrequency, digestTime, digestDays, timezone } = options;
    const vendor = scenario === 'vendor';
    const failure = scenario === 'failure';
    const isDigest = scenario === 'digest' || (scenario === 'package' && immediateSeverities.length === 0);
    const admin: OutputState = vendor || failure ? 'new' : 'inactive';
    const team: OutputState = failure ? 'inactive' : 'new';
    const make = (
        node: NodeId,
        title: string,
        caption: string,
        time: string,
        path?: EdgeId,
        packet: Scene['packet'] = 'N01',
        state: Partial<Pick<Scene, 'admin' | 'team'>> = {},
    ): Scene => ({ node, title, caption, time, path, packet, admin, team, ...state });
    const scenes = [
        make('source', t('Tin đã có ở nguồn'), t('PSM biết đến tin khi đến lượt thu thập kế tiếp. Đường đứt thể hiện hướng kết nối.'), t('Chờ lượt đọc')),
        make('collect', t('Đến lượt thu thập'), t('Thu thập dữ liệu và gửi thông báo là hai bước riêng.'), collectionFrequency, 'collect'),
    ];
    if (failure) {
        scenes.push(
            make(
                'admin',
                t('Admin nhận cảnh báo nguồn'),
                t('Nguồn lỗi hoặc quá cũ vượt ngưỡng cấu hình. Thiếu dữ liệu không đồng nghĩa với service an toàn.'),
                t('Sau ngưỡng cấu hình'),
                'source-failed',
                'A01',
                { admin: 'sent' },
            ),
        );
        return scenes;
    }
    scenes.push(
        make(
            'classify',
            vendor ? t('Phân loại tin bảo mật') : t('Chuẩn hoá dữ liệu'),
            vendor ? t('AI đọc tin trước khi xét điều kiện cho từng đầu ra.') : t('Chuẩn hoá advisory, package hoặc CVE để đối chiếu với service.'),
            t('Sau thu thập'),
            'classify',
        ),
    );
    if (vendor) {
        scenes.push(
            make(
                'admin',
                t('Một đầu ra riêng cho admin'),
                t('Tin vendor đủ điều kiện tạo A01. Nhánh này không phụ thuộc kết quả tìm team.'),
                t('Nhánh độc lập'),
                'news-admin',
                'A01',
                { admin: 'sent' },
            ),
        );
    }
    const adminAfter: OutputState = vendor ? 'sent' : 'inactive';
    scenes.push(
        make(
            'assess',
            hasTeam ? t('Tìm được team liên quan') : t('Không có team phù hợp'),
            hasTeam
                ? t('K01 đối chiếu công nghệ, version hoặc package để xác định team có service liên quan.')
                : t('Không tạo T01. A01 của admin vẫn giữ trạng thái đã gửi trong ví dụ này.'),
            t('Sau đối chiếu'),
            'assess',
            'K01',
            { admin: adminAfter, team: hasTeam ? 'ready' : 'skipped' },
        ),
    );
    if (!hasTeam) {
        return scenes;
    }
    if (isDigest) {
        scenes.push(
            make(
                'digest',
                t('Chờ giờ bản tin'),
                t('Các nội dung đủ điều kiện được giữ và gom theo team; chưa gọi webhook ngay.'),
                t(':time :timezone · :days', { time: digestTime, timezone, days: digestDays }),
                'digest',
                'K01',
                { admin: adminAfter, team: 'waiting' },
            ),
        );
        scenes.push(
            make(
                'webhook',
                t('Nhiều nội dung, một bản tin'),
                t('K01 và K02 minh hoạ hai nội dung được gom vào D01, không phải hai thông báo riêng.'),
                t('Đến lịch gửi'),
                'digest-send',
                'D01',
                { admin: adminAfter, team: 'queued' },
            ),
        );
    } else {
        scenes.push(
            make(
                'immediate',
                t('Chọn nhánh gửi ngay'),
                scenario === 'package'
                    ? t('Mức gửi ngay đang cấu hình: :levels.', { levels: immediateSeverities.join(' · ') })
                    : t('Tin đủ điều kiện tạo thông báo cho team liên quan theo chính sách.'),
                t('Theo chính sách'),
                'immediate',
                'T01',
                { admin: adminAfter, team: 'ready' },
            ),
        );
        scenes.push(
            make('webhook', t('Thông báo vào queue'), t('Worker chọn webhook của team. A01 và T01 là các lần gửi riêng.'), t('Chờ worker'), 'send', 'T01', {
                admin: adminAfter,
                team: 'queued',
            }),
        );
    }
    if (retry) {
        scenes.push(
            make('webhook', t('Webhook team gặp lỗi tạm thời'), t('T01 chưa gửi thành công. Trạng thái đã gửi của A01 không thay đổi.'), t('Lần gửi minh hoạ'), 'retry', 'T01', {
                admin: adminAfter,
                team: 'failed',
            }),
        );
        scenes.push(
            make(
                'webhook',
                t('Thử lại sau khoảng chờ'),
                t('Khoảng chờ và số lần thử phụ thuộc cấu hình. Đây chỉ là một lần thử lại minh hoạ.'),
                t('Theo cấu hình retry'),
                'retry',
                'T01',
                { admin: adminAfter, team: 'retry' },
            ),
        );
    }
    scenes.push(
        make(
            'team',
            isDigest ? t('Bản tin D01 tới space của team') : t('Thông báo T01 tới space của team'),
            t('Đây là ví dụ minh hoạ, không gửi thông báo thật. Thứ tự kể không phải thứ tự hoàn thành của các nhánh.'),
            t('Kết quả minh hoạ'),
            'deliver',
            isDigest ? 'D01' : 'T01',
            { admin: adminAfter, team: 'sent' },
        ),
    );
    return scenes;
}

export function nodeDetails(node: NodeId, options: WorkflowOptions, t: Translate): { title: string; summary: string; facts: [string, string][] } {
    const { scenario, collectionFrequency, digestTime, digestDays, timezone, immediateSeverities, noticeRelevantDays } = options;
    const vendor = scenario === 'vendor';
    const details: Record<NodeId, { title: string; summary: string; facts: [string, string][] }> = {
        source: {
            title: t('Nguồn dữ liệu đầu vào'),
            summary: t('Mỗi loại tin bắt đầu từ nguồn tương ứng.'),
            facts: [
                [
                    t('Nguồn'),
                    vendor ? 'PHP / Node.js / Ruby' : scenario === 'kev' ? 'CISA KEV' : scenario === 'failure' ? t('Feed và API đang theo dõi') : 'Packagist / RubySec / OSV',
                ],
                [t('Nội dung'), t('Dữ liệu công bố tại nguồn; chưa đồng nghĩa với việc có thông báo mới.')],
            ],
        },
        collect: {
            title: t('Thu thập theo lịch'),
            summary: t('Scheduler đưa dữ liệu về hệ thống trước khi xét người nhận.'),
            facts: [
                [t('Nhịp chạy'), collectionFrequency],
                [t('Thực hiện'), vendor ? t('Đọc feed và chuyển tin sang bước phân loại bảo mật.') : t('Đọc dữ liệu nguồn để đối chiếu với service đang theo dõi.')],
            ],
        },
        classify: {
            title: vendor ? t('Phân loại tin bảo mật') : t('Chuẩn hoá dữ liệu'),
            summary: vendor ? t('AI đọc tin trước khi xét điều kiện cho từng đầu ra.') : t('Chuẩn hoá advisory, package hoặc CVE để đối chiếu với service.'),
            facts: [[t('Đầu ra'), t('Thông tin cho từng nhánh; chưa phụ thuộc kết quả khớp team.')]],
        },
        assess: {
            title: t('Tìm team có service liên quan'),
            summary: t('Đối chiếu công nghệ, version hoặc package đang chạy để tìm team liên quan.'),
            facts: [
                [t('Có kết quả'), t('Xét chính sách gửi cho team sở hữu service.')],
                [t('Không khớp'), t('Không tạo T01. Nhánh admin vẫn xét điều kiện riêng.')],
            ],
        },
        immediate: {
            title: t('Gửi ngay khi đủ điều kiện'),
            summary: t('Đây là quyết định gửi, chưa xác nhận tin đã tới Google Chat.'),
            facts: [
                [
                    t('Điều kiện'),
                    vendor
                        ? t('Tin Critical, High hoặc chưa rõ mức trong :count ngày gần đây.', { count: noticeRelevantDays })
                        : scenario === 'kev'
                          ? t('CVE trong KEV khớp finding của service.')
                          : immediateSeverities.join(' · ') || t('Không có mức gửi ngay được cấu hình.'),
                ],
                [t('Tiếp theo'), t('Đưa thông báo vào queue để worker gửi webhook.')],
            ],
        },
        digest: {
            title: t('Chờ bản tin tổng hợp'),
            summary: t('Gom nội dung đủ điều kiện theo team; không có nội dung mới thì không gửi.'),
            facts: [
                [t('Thời điểm'), `${digestTime} ${timezone}`],
                [t('Ngày gửi'), digestDays],
            ],
        },
        webhook: {
            title: t('Queue worker gửi webhook'),
            summary: t('Worker gửi HTTP POST tới webhook đã cấu hình của người nhận.'),
            facts: [[t('Khi lỗi'), t('Thử lại lỗi tạm thời theo cấu hình; tin không gửi được sẽ được báo cho admin.')]],
        },
        team: {
            title: t('Team nhận trong Google Chat'),
            summary: t('Mỗi team nhận tại space tương ứng với webhook của mình.'),
            facts: [
                [t('Nội dung'), t('T01 là thông báo riêng; D01 là bản tin tổng hợp.')],
                [t('Ví dụ'), t('Team Backend chỉ là tên minh hoạ.')],
            ],
        },
        admin: {
            title: scenario === 'failure' ? t('Kênh admin vận hành') : t('Kênh tin bảo mật của admin'),
            summary: scenario === 'failure' ? t('Admin được báo để xử lý sự cố thu thập dữ liệu.') : t('Admin có kênh nhận tin bảo mật vendor theo cấu hình.'),
            facts: [
                [t('Nơi nhận'), scenario === 'failure' ? t('Webhook cảnh báo vận hành của admin.') : t('Webhook tin bảo mật, hoặc kênh admin dự phòng theo cấu hình.')],
                [t('Cần biết'), t('Nhánh này độc lập với việc có team chạy công nghệ liên quan.')],
            ],
        },
    };
    return details[node];
}
