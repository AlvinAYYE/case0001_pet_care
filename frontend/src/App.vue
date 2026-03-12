<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import {
  CalendarDays,
  Clock3,
  ExternalLink,
  Facebook,
  Instagram,
  MapPin,
  Menu,
  MessageCircle,
  PawPrint,
  Phone,
  ShieldCheck,
  X,
} from 'lucide-vue-next'

type ApiSection = {
  section_key?: string
  title?: string | null
  content?: string | null
}

type ApiExternalLink = {
  platform?: string
  label?: string | null
  url?: string
  is_active?: number | boolean
  sort_order?: number
}

type ApiStoreInfo = {
  store_name?: string | null
  phone?: string | null
  address?: string | null
  business_hours?: string | null
  line_url?: string | null
  ig_url?: string | null
  fb_url?: string | null
  map_url?: string | null
}

type ApiContentResponse = {
  success?: boolean
  data?: {
    sections?: ApiSection[]
    media?: ApiMediaAsset[]
    links?: ApiExternalLink[]
    store_info?: ApiStoreInfo
    about_content?: ApiAboutContent
    about_modals?: ApiAboutModal[]
    articles?: ApiNewsItem[]
    senior_care?: ApiSeniorContent
  }
}

type ApiMediaAsset = {
  media_type?: 'image' | 'poster' | string
  title?: string | null
  file_path?: string
  is_active?: number | boolean
  sort_order?: number
}

type ApiAboutContent = {
  title?: string
  content?: string
}

type ApiAboutModal = {
  modal_key?: string
  title?: string
  content?: string
  image_url?: string | null
}

type ApiAboutPayload = {
  content?: ApiAboutContent
  modals?: ApiAboutModal[]
}

type ApiNewsItem = {
  id?: number | string
  title?: string
  excerpt?: string
  summary?: string
  content?: string
  imageUrl?: string
  image_url?: string
  publishedAt?: string
  published_at?: string
  date?: string
  link?: string
}

type NewsItem = {
  id: string
  title: string
  excerpt: string
  imageUrl?: string
  publishedAt: string
  link: string
}

type ApiSeniorContent = {
  title?: string
  subtitle?: string
  description?: string
  address?: string
  tags?: string
  imageUrl?: string
  image_url?: string
}

type ContentSection = {
  key: string
  title: string
  content: string
}

type StoreInfo = {
  storeName: string
  phone: string
  address: string
  businessHours: string
  lineUrl: string
  igUrl: string
  fbUrl: string
  mapUrl: string
}

type ExternalLinkItem = {
  platform: string
  label: string
  url: string
  isActive: boolean
  sortOrder: number
}

type SeniorContent = {
  title: string
  subtitle: string
  description: string
  tags: string[]
  imageUrl: string
}

type AboutModalKey = 'assessment' | 'hours' | 'manager'

type ArticleShareItem = {
  id: string
  title: string
  excerpt: string
  imageUrl?: string
  publishedAt?: string
  link?: string
}

type AboutContentState = {
  title: string
  content: string
}

type AboutModalState = {
  modalKey: string
  title: string
  content: string
  imageUrl?: string
}

type MediaAssetItem = {
  mediaType: string
  title: string
  filePath: string
  isActive: boolean
  sortOrder: number
}

const defaultAboutContent: AboutContentState = {
  title: '專門為高齡犬與需要安靜休養的毛孩，留一個更溫柔的空間',
  content:
    '當毛孩年輕時，活潑、奔跑、充滿活力。但當牠們慢慢變老，腳步變慢、需要更多休息與照顧時，許多飼主也會跟著感到不安與焦慮。\n\n恆寵愛 Perpetuity 的成立，是希望提供一個更溫柔的選擇。\n\n我們理解那份心情，因此選擇提供更安靜、更細心、更理解高齡毛孩需求的環境，也希望分享高齡犬照顧的經驗與知識，陪伴飼主走過生命的每個階段。',
}

const defaultAboutModals: AboutModalState[] = [
  {
    modalKey: 'daycare_assessment',
    title: '入住前安親評估',
    content:
      '入住前會先了解毛孩的作息、飲食習慣、身體狀況與照護需求，協助牠用更低壓的方式熟悉環境，也讓飼主更放心安排後續住宿。',
  },
  {
    modalKey: 'hours',
    title: '服務時間',
    content: '',
  },
  {
    modalKey: 'manager',
    title: '店長介紹',
    content:
      '由熟悉高齡毛孩照護節奏的照護者陪伴，依照每隻毛孩的個性、體力與生活習慣調整互動方式，讓住宿不只是安置，而是安心的照顧延續。',
  },
]

const defaultStoreInfo: StoreInfo = {
  storeName: '恆寵愛 Perpetuity',
  phone: '02 8791 7135',
  address: '台北市萬華區（預約後提供）',
  businessHours: '每日 09:30 - 21:30（全年無休）',
  lineUrl: 'https://www.line.me/tw/',
  igUrl: 'https://www.instagram.com/',
  fbUrl: 'https://www.facebook.com/',
  mapUrl: '',
}

const defaultArticleShare: ArticleShareItem[] = [
  {
    id: 'article-1',
    title: '當毛孩開始慢慢變老，最需要的是被理解的節奏',
    excerpt: '當毛孩年輕時，活潑、奔跑、充滿活力。但當牠們慢慢變老，腳步變慢、需要更多休息與照顧時，飼主也會跟著不安。',
  },
  {
    id: 'article-2',
    title: '安靜休養，不只是空間更是日常安排',
    excerpt: '一般寵物旅館節奏較快，對高齡犬或害羞毛孩容易造成壓力。我們更重視低刺激、安靜、細心的生活安排與照顧密度。',
  },
  {
    id: 'article-3',
    title: '陪伴熟齡毛孩走過每個階段',
    excerpt: '我們希望分享高齡犬照顧的經驗與知識，陪伴飼主走過毛孩生命的每個階段，讓每一份生命都被好好照顧。',
  },
]

const mobileOpen = ref(false)
const activeAboutModal = ref<AboutModalKey | null>(null)
const loadingNews = ref(false)
const newsError = ref('')
const latestNews = ref<NewsItem[]>([])
const articleError = ref('')
const articleItems = ref<ArticleShareItem[]>([])
const loadingSenior = ref(false)
const seniorError = ref('')
const sectionMap = ref<Record<string, ContentSection>>({})
const mediaAssets = ref<MediaAssetItem[]>([])
const externalLinks = ref<ExternalLinkItem[]>([])
const storeInfo = ref<StoreInfo>(defaultStoreInfo)
const aboutContent = ref<AboutContentState>(defaultAboutContent)
const aboutModals = ref<AboutModalState[]>(defaultAboutModals)

const resolveAppAssetUrl = (path: string): string => new URL(path, window.location.href).toString()

const inferredApiBaseUrl = import.meta.env.DEV
  ? '/api'
  : new URL('../../backend/public/api/', window.location.href).toString().replace(/\/$/, '')

const apiBaseUrl =
    import.meta.env.VITE_API_BASE_URL ||
    inferredApiBaseUrl

const mediaBaseUrl = apiBaseUrl.replace(/\/api\/?$/, '')
const contentApiPath = '/content'
const articlesApiPath = '/articles'
const aboutApiPath = '/about'

const apiClient = axios.create({
  baseURL: apiBaseUrl,
  timeout: 10000,
})

const cleanText = (value?: string | null): string => value?.trim() || ''

const resolveMediaUrl = (raw?: string): string | undefined => {
  if (!raw) {
    return undefined
  }

  const value = raw.trim()
  if (value === '') {
    return undefined
  }

  if (/^(https?:)?\/\//i.test(value) || value.startsWith('data:') || value.startsWith('blob:')) {
    return value
  }

  if (value.startsWith('/')) {
    if (value.startsWith('/uploads/')) {
      return `${mediaBaseUrl}${value}`
    }

    return resolveAppAssetUrl(value.replace(/^\/+/, ''))
  }

  return `${mediaBaseUrl}/${value}`
}

const parseTagList = (raw?: string): string[] => {
  const value = cleanText(raw)
  if (value === '') {
    return []
  }

  const hashtagMatches = value.match(/#([^#\s,，、/|]+)/g)
  if (hashtagMatches) {
    return Array.from(new Set(hashtagMatches.map((item) => item.replace(/^#/, '').trim()).filter(Boolean)))
  }

  return Array.from(
    new Set(
      value
        .split(/[\n,，、/|]+/)
        .map((item) => item.trim().replace(/^#/, ''))
        .filter(Boolean),
    ),
  )
}

const normalizeSection = (item: ApiSection): ContentSection | undefined => {
  const key = cleanText(item.section_key)
  if (key === '') {
    return undefined
  }

  return {
    key,
    title: cleanText(item.title),
    content: cleanText(item.content),
  }
}

const normalizeStoreInfo = (data?: ApiStoreInfo): StoreInfo => ({
  storeName: cleanText(data?.store_name) || defaultStoreInfo.storeName,
  phone: cleanText(data?.phone) || defaultStoreInfo.phone,
  address: cleanText(data?.address) || defaultStoreInfo.address,
  businessHours: cleanText(data?.business_hours) || defaultStoreInfo.businessHours,
  lineUrl: cleanText(data?.line_url) || defaultStoreInfo.lineUrl,
  igUrl: cleanText(data?.ig_url) || defaultStoreInfo.igUrl,
  fbUrl: cleanText(data?.fb_url) || defaultStoreInfo.fbUrl,
  mapUrl: cleanText(data?.map_url) || defaultStoreInfo.mapUrl,
})

const normalizeExternalLink = (item: ApiExternalLink): ExternalLinkItem | undefined => {
  const platform = cleanText(item.platform).toLowerCase()
  const url = cleanText(item.url)
  if (platform === '' || url === '') {
    return undefined
  }

  return {
    platform,
    label: cleanText(item.label) || platform,
    url,
    isActive: item.is_active === undefined ? true : Boolean(item.is_active),
    sortOrder: Number(item.sort_order ?? 0),
  }
}

const normalizeMediaAsset = (item: ApiMediaAsset): MediaAssetItem | undefined => {
  const filePath = cleanText(item.file_path)
  if (filePath === '') {
    return undefined
  }

  return {
    mediaType: cleanText(item.media_type).toLowerCase() || 'image',
    title: cleanText(item.title),
    filePath,
    isActive: item.is_active === undefined ? true : Boolean(item.is_active),
    sortOrder: Number(item.sort_order ?? 0),
  }
}

const getSectionEntry = (keys: string[]): ContentSection | undefined => {
  for (const key of keys) {
    const match = sectionMap.value[key]
    if (match) {
      return match
    }
  }

  return undefined
}

const getSectionTitle = (keys: string[], fallback: string): string => {
  const match = getSectionEntry(keys)
  return match?.title || fallback
}

const getSectionContent = (keys: string[], fallback: string): string => {
  const match = getSectionEntry(keys)
  return match?.content || fallback
}

const getSectionImageUrl = (keys: string[]): string | undefined => {
  const match = getSectionEntry(keys)
  return resolveMediaUrl(match?.content)
}

const getPlatformUrl = (platforms: string[], fallback: string): string => {
  for (const platform of platforms) {
    const match = externalLinks.value.find((item) => item.isActive && item.platform === platform)
    if (match?.url) {
      return match.url
    }
  }

  return fallback
}

const defaultHeroTitle = '恆寵愛｜Perpetuity'
const defaultHeroDescription = '長久陪伴、永恆的愛。為高齡犬與需要安靜休養的毛孩，打造低刺激、好休息、可安心託付的日常照護空間。'
const defaultHeroImage = resolveAppAssetUrl('hero-main.jpg')
const mapImageUrl = resolveAppAssetUrl('map.png')

const getHeroImageFromMedia = (): string | undefined => {
  const activeMedia = mediaAssets.value
    .filter((item) => item.isActive)
    .sort((left, right) => left.sortOrder - right.sortOrder)

  const preferred = activeMedia.find((item) => item.mediaType === 'poster')
    || activeMedia.find((item) => item.mediaType === 'image')

  return resolveMediaUrl(preferred?.filePath)
}

const heroTitle = computed(() => getSectionTitle(['hero_title', 'hero'], defaultHeroTitle))

const heroDescription = computed(() =>
  getSectionContent(['hero_subtitle', 'hero_content'], getSectionContent(['hero'], defaultHeroDescription)) || defaultHeroDescription,
)

const heroImageUrl = computed(() =>
  getSectionImageUrl(['hero_visual', 'hero_image', 'hero_cover']) || getHeroImageFromMedia() || defaultHeroImage,
)

const heroStyle = computed(() => ({
  backgroundImage: `linear-gradient(110deg, rgba(255,242,224,0.85), rgba(210,178,144,0.55)), url('${heroImageUrl.value}')`,
}))

const splitParagraphs = (raw: string): string[] =>
  raw
    .split(/\n\s*\n+/)
    .map((item) => item.trim())
    .filter(Boolean)

const normalizeAboutContent = (data?: ApiAboutContent): AboutContentState => ({
  title: cleanText(data?.title) || defaultAboutContent.title,
  content: cleanText(data?.content) || defaultAboutContent.content,
})

const normalizeAboutModal = (item: ApiAboutModal): AboutModalState | undefined => {
  const modalKey = cleanText(item.modal_key)
  if (modalKey === '') {
    return undefined
  }

  return {
    modalKey,
    title: cleanText(item.title),
    content: cleanText(item.content),
    imageUrl: resolveMediaUrl(item.image_url || undefined),
  }
}

const normalizeArticleItem = (item: ApiNewsItem, index: number): ArticleShareItem => ({
  id: String(item.id ?? `article-${index + 1}`),
  title: cleanText(item.title) || '未命名文章',
  excerpt: cleanText(item.excerpt || item.summary || item.content) || '尚無摘要',
  imageUrl: resolveMediaUrl(item.imageUrl || item.image_url),
  publishedAt: cleanText(item.publishedAt || item.published_at || item.date),
  link: cleanText(item.link),
})

const seniorContent = ref<SeniorContent>({
  title: '樂齡館',
  subtitle: '高齡犬與特殊需求毛孩照護',
  description: '以低量收托與個別照護為原則，依需求調整生活安排，協助高齡犬維持穩定、降低壓力，守住生活品質。',
  tags: ['高齡犬照護', '安靜休養', '低量收托'],
  imageUrl: 'https://images.unsplash.com/photo-1537151625747-768eb6cf92b2?auto=format&fit=crop&w=2200&q=95',
})

const newsApiPath = '/news'
const seniorApiPath = '/senior-care'

const fallbackNews: NewsItem[] = [
  {
    id: 'fallback-1',
    title: '恆寵愛樂齡館公告',
    excerpt: '目前後端最新消息尚未完成串接，完成後將自動顯示即時內容。',
    publishedAt: '待更新',
    link: '',
  },
]

const normalizeNews = (item: ApiNewsItem, index: number): NewsItem => {
  const rawDate = item.publishedAt || item.published_at || item.date || ''
  return {
    id: String(item.id ?? index + 1),
    title: item.title?.trim() || '未命名消息',
    excerpt: (item.excerpt || item.summary || item.content || '').trim() || '尚無摘要',
    imageUrl: resolveMediaUrl(item.imageUrl || item.image_url),
    publishedAt: rawDate ? rawDate.slice(0, 10) : '待更新',
    link: item.link?.trim() || '',
  }
}

const fetchLatestNews = async () => {
  loadingNews.value = true
  newsError.value = ''

  try {
    const response = await apiClient.get<ApiNewsItem[]>(newsApiPath)
    const payload = Array.isArray(response.data) ? response.data : []

    if (!payload.length) {
      latestNews.value = fallbackNews
      newsError.value = '目前後端尚無資料，先顯示預設訊息。'
      return
    }

    latestNews.value = payload.slice(0, 4).map(normalizeNews)
  } catch (_error) {
    latestNews.value = fallbackNews
    newsError.value = '最新消息讀取失敗，請確認後端 API。'
  } finally {
    loadingNews.value = false
  }
}

const normalizeSeniorContent = (data: ApiSeniorContent): SeniorContent => {
  const parsedTags = parseTagList(data.tags || data.address)

  return {
    title: data.title?.trim() || seniorContent.value.title,
    subtitle: data.subtitle?.trim() || seniorContent.value.subtitle,
    description: data.description?.trim() || seniorContent.value.description,
    tags: parsedTags.length > 0 ? parsedTags : seniorContent.value.tags,
    imageUrl: resolveMediaUrl(data.imageUrl || data.image_url) || seniorContent.value.imageUrl,
  }
}

const fetchSiteContent = async () => {
  try {
    const response = await apiClient.get<ApiContentResponse>(contentApiPath)
    const payload = response.data?.data
    if (!payload) {
      return
    }

    const normalizedSections = (Array.isArray(payload.sections) ? payload.sections : [])
      .map(normalizeSection)
      .filter((item): item is ContentSection => Boolean(item))

    mediaAssets.value = (Array.isArray(payload.media) ? payload.media : [])
      .map(normalizeMediaAsset)
      .filter((item): item is MediaAssetItem => Boolean(item))

    sectionMap.value = Object.fromEntries(normalizedSections.map((item) => [item.key, item]))
    externalLinks.value = (Array.isArray(payload.links) ? payload.links : [])
      .map(normalizeExternalLink)
      .filter((item): item is ExternalLinkItem => Boolean(item))
      .sort((left, right) => left.sortOrder - right.sortOrder)
    storeInfo.value = normalizeStoreInfo(payload.store_info)

    if (payload.about_content) {
      aboutContent.value = normalizeAboutContent(payload.about_content)
    }

    const normalizedAboutModals = (Array.isArray(payload.about_modals) ? payload.about_modals : [])
      .map(normalizeAboutModal)
      .filter((item): item is AboutModalState => Boolean(item))
    if (normalizedAboutModals.length > 0) {
      aboutModals.value = normalizedAboutModals
    }

    const normalizedArticles = (Array.isArray(payload.articles) ? payload.articles : [])
      .map(normalizeArticleItem)
    if (normalizedArticles.length > 0) {
      articleItems.value = normalizedArticles
    }

    if (payload.senior_care) {
      seniorContent.value = normalizeSeniorContent(payload.senior_care)
    }
  } catch (_error) {
    sectionMap.value = {}
    mediaAssets.value = []
    externalLinks.value = []
    storeInfo.value = defaultStoreInfo
    aboutContent.value = defaultAboutContent
    aboutModals.value = defaultAboutModals
  }
}

const fetchArticleShares = async () => {
  articleError.value = ''

  try {
    const response = await apiClient.get<ApiNewsItem[]>(articlesApiPath)
    const payload = Array.isArray(response.data) ? response.data : []
    if (!payload.length) {
      return
    }

    articleItems.value = payload.map(normalizeArticleItem)
  } catch (_error) {
    articleError.value = '文章分享讀取失敗，暫時顯示預設內容。'
  }
}

const fetchAboutPayload = async () => {
  try {
    const response = await apiClient.get<ApiAboutPayload>(aboutApiPath)
    if (response.data?.content) {
      aboutContent.value = normalizeAboutContent(response.data.content)
    }

    const normalizedAboutModals = (Array.isArray(response.data?.modals) ? response.data.modals : [])
      .map(normalizeAboutModal)
      .filter((item): item is AboutModalState => Boolean(item))

    if (normalizedAboutModals.length > 0) {
      aboutModals.value = normalizedAboutModals
    }
  } catch (_error) {
    // Keep bundle/fallback content.
  }
}

const aboutTitle = computed(() =>
  aboutContent.value.title || getSectionTitle(['about_intro', 'about'], defaultAboutContent.title),
)

const aboutParagraphs = computed(() => {
  const rawContent = cleanText(aboutContent.value.content) || getSectionContent(['about_intro', 'about'], defaultAboutContent.content)
  const parts = splitParagraphs(rawContent)
  return parts.length > 0 ? parts : splitParagraphs(defaultAboutContent.content)
})

const aboutIntro = computed(() =>
  aboutParagraphs.value.slice(0, 2).join('\n\n'),
)

const aboutSecondary = computed(() =>
  aboutParagraphs.value.slice(2).join('\n\n') || getSectionContent(['about_secondary'], ''),
)

const getAboutModalByKey = (modalKey: string): AboutModalState | undefined =>
  aboutModals.value.find((item) => item.modalKey === modalKey)

const aboutModalItems = computed(() => {
  const businessHours = storeInfo.value.businessHours
  const daycareAssessmentModal = getAboutModalByKey('daycare_assessment')
  const hoursModal = getAboutModalByKey('hours')
  const managerModal = getAboutModalByKey('manager')

  return [
    {
      id: 'assessment' as const,
      icon: ShieldCheck,
      badge: daycareAssessmentModal?.title || getSectionTitle(['about_assessment_badge'], '入住前安親評估'),
      summary: '',
      title: daycareAssessmentModal?.title || getSectionTitle(['about_assessment'], '入住前安親評估'),
      content:
        daycareAssessmentModal?.content ||
        getSectionContent(
          ['about_assessment'],
          defaultAboutModals.find((item) => item.modalKey === 'daycare_assessment')?.content || '',
        ),
      imageUrl: daycareAssessmentModal?.imageUrl,
    },
    {
      id: 'hours' as const,
      icon: Clock3,
      badge: getSectionTitle(['about_hours_badge'], '時間'),
      summary: businessHours,
      title: hoursModal?.title || getSectionTitle(['about_hours'], '服務時間'),
      content:
        hoursModal?.content ||
        getSectionContent(
          ['about_hours'],
          `目前服務時間為 ${businessHours}。如需安排入住前評估、接送或特殊照護，建議先行預約，以便預留更合適的照護節奏。`,
        ),
      imageUrl: hoursModal?.imageUrl,
    },
    {
      id: 'manager' as const,
      icon: PawPrint,
      badge: managerModal?.title || getSectionTitle(['about_manager_badge'], '店長'),
      summary: '',
      title: managerModal?.title || getSectionTitle(['about_manager'], '店長介紹'),
      content:
        managerModal?.content ||
        getSectionContent(
          ['about_manager'],
          defaultAboutModals.find((item) => item.modalKey === 'manager')?.content || '',
        ),
      imageUrl: managerModal?.imageUrl || getSectionImageUrl(['about_manager_photo']),
    },
  ]
})

const activeAboutItem = computed(() => aboutModalItems.value.find((item) => item.id === activeAboutModal.value) || null)

const articleShareHeading = computed(() => getSectionTitle(['article_share_intro', 'article_share'], '文章分享'))

const articleShareIntro = computed(() =>
  getSectionContent(
    ['article_share_intro', 'article_share'],
    '整理高齡犬照護與熟齡毛孩生活節奏的分享，讓飼主在日常陪伴裡，更容易找到安心的方向。',
  ),
)

const articleShareItems = computed<ArticleShareItem[]>(() => {
  if (articleItems.value.length > 0) {
    return articleItems.value
  }

  const dynamicItems = [1, 2, 3]
    .map((index) => ({
      id: `article-${index}`,
      title: getSectionTitle([`article_share_${index}`], ''),
      excerpt: getSectionContent([`article_share_${index}`], ''),
    }))
    .filter((item) => item.title !== '' && item.excerpt !== '')

  return dynamicItems.length > 0 ? dynamicItems : defaultArticleShare
})

const socialLinks = computed(() => [
  {
    key: 'line',
    label: 'LINE',
    icon: MessageCircle,
    url: getPlatformUrl(['line'], storeInfo.value.lineUrl),
  },
  {
    key: 'instagram',
    label: 'Instagram',
    icon: Instagram,
    url: getPlatformUrl(['instagram', 'ig'], storeInfo.value.igUrl),
  },
  {
    key: 'facebook',
    label: 'Facebook',
    icon: Facebook,
    url: getPlatformUrl(['facebook', 'fb'], storeInfo.value.fbUrl),
  },
].filter((item) => item.url !== ''))

const contactMapUrl = computed(() => getPlatformUrl(['map', 'google-map', 'google_maps'], storeInfo.value.mapUrl))

const fetchSeniorContent = async () => {
  loadingSenior.value = true
  seniorError.value = ''

  try {
    const response = await apiClient.get<ApiSeniorContent>(seniorApiPath)
    seniorContent.value = normalizeSeniorContent(response.data || {})
  } catch (_error) {
    seniorError.value = '樂齡館資料讀取失敗，暫時顯示預設內容。'
  } finally {
    loadingSenior.value = false
  }
}

onMounted(async () => {
  await fetchSiteContent()
  await Promise.all([fetchLatestNews(), fetchSeniorContent(), fetchAboutPayload(), fetchArticleShares()])
})
</script>

<template>
  <div class="relative min-h-screen bg-[#f3e7d7] text-[#2d241c]">

    <header class="sticky top-0 z-50 isolate border-b border-white/45 shadow-[0_14px_28px_rgba(57,39,24,0.18)]">
      <div
          class="pointer-events-none absolute inset-0 bg-[#f4e8d8bf] backdrop-blur-sm supports-[backdrop-filter]:bg-[#f4e8d8b3]"
      ></div>
      <div
          class="relative mx-auto grid min-h-20 w-[min(1200px,calc(100%-2rem))] grid-cols-[1fr_auto_1fr] items-center gap-4">
        <a href="#home"
           class="col-start-2 inline-flex items-center justify-self-center gap-2 text-lg font-semibold tracking-wide text-[#3e3329]">
          <img src="/logo.jpg" alt="恆寵愛 Logo" class="h-9 w-9 rounded-full border border-[#d2bda0] object-cover"/>
          <span>{{ storeInfo.storeName }}</span>
        </a>

        <button
            type="button"
            class="group relative col-start-3 inline-flex h-11 w-11 items-center justify-center justify-self-end rounded-lg border border-[#d2bda0] bg-[#fff9f2] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_8px_14px_rgba(76,53,31,0.2)]"
            :class="mobileOpen ? 'scale-[1.03] bg-[#f2dfc6] shadow-[0_8px_14px_rgba(76,53,31,0.2)]' : ''"
            @click="mobileOpen = !mobileOpen"
            aria-label="開啟選單"
        >
          <span class="relative inline-block h-[18px] w-[18px]" aria-hidden="true">
            <Menu
                :size="18"
                class="absolute inset-0 transition-all duration-200"
                :class="mobileOpen ? 'scale-75 rotate-[70deg] opacity-0' : 'scale-100 opacity-100'"
            />
            <X
                :size="18"
                class="absolute inset-0 transition-all duration-200"
                :class="mobileOpen ? 'scale-100 rotate-0 opacity-100' : 'scale-75 -rotate-[70deg] opacity-0'"
            />
          </span>
        </button>

        <nav
            id="nav"
            class="z-50 fixed left-2.5 right-2.5 top-[5.35rem] w-auto overflow-hidden rounded-lg border border-white/45
            shadow-[0_14px_28px_rgba(57,39,24,0.18)] sm:left-4 sm:right-4 md:absolute md:right-0 md:left-auto
            md:top-[calc(100%+0.35rem)] md:col-start-3 md:w-[min(380px,calc(100vw-2rem))]
            transition-all duration-200"
            :class="mobileOpen
            ? 'pointer-events-auto max-h-80 translate-y-0 scale-100 opacity-100'
            : 'pointer-events-none max-h-0 -translate-y-2 scale-95 border-transparent opacity-0'"
        >
          <div class="flex flex-col items-start gap-2 bg-[#f4e8d8bf] p-3 backdrop-blur-sm supports-[backdrop-filter]:bg-[#f4e8d8b3]">
            <a
                href="#about"
                class="relative w-full overflow-hidden rounded-md px-4 py-2.5 text-center text-base font-semibold text-[#4a3f35] transition duration-200 hover:-translate-y-0.5 hover:bg-[#f0ddc1] hover:text-[#5a402a] hover:shadow-[0_8px_18px_rgba(85,58,34,0.18)] after:content-[''] after:absolute after:left-4 after:right-4 after:bottom-1.5 after:h-[2px] after:origin-center after:scale-x-0 after:rounded-full after:bg-[linear-gradient(90deg,#b18053,#d9ab7e)] after:transition-transform after:duration-300 hover:after:scale-x-100"
                @click="mobileOpen = false"
            >關於恆寵愛</a
            >
            <a
                href="#news"
                class="relative w-full overflow-hidden rounded-md px-4 py-2.5 text-center text-base font-semibold text-[#4a3f35] transition duration-200 hover:-translate-y-0.5 hover:bg-[#f0ddc1] hover:text-[#5a402a] hover:shadow-[0_8px_18px_rgba(85,58,34,0.18)] after:content-[''] after:absolute after:left-4 after:right-4 after:bottom-1.5 after:h-[2px] after:origin-center after:scale-x-0 after:rounded-full after:bg-[linear-gradient(90deg,#b18053,#d9ab7e)] after:transition-transform after:duration-300 hover:after:scale-x-100"
                @click="mobileOpen = false"
            >最新消息</a
            >
            <a
                href="#articles"
                class="relative w-full overflow-hidden rounded-md px-4 py-2.5 text-center text-base font-semibold text-[#4a3f35] transition duration-200 hover:-translate-y-0.5 hover:bg-[#f0ddc1] hover:text-[#5a402a] hover:shadow-[0_8px_18px_rgba(85,58,34,0.18)] after:content-[''] after:absolute after:left-4 after:right-4 after:bottom-1.5 after:h-[2px] after:origin-center after:scale-x-0 after:rounded-full after:bg-[linear-gradient(90deg,#b18053,#d9ab7e)] after:transition-transform after:duration-300 hover:after:scale-x-100"
                @click="mobileOpen = false"
            >文章分享</a
            >
            <a
                href="#senior"
                class="relative w-full overflow-hidden rounded-md px-4 py-2.5 text-center text-base font-semibold text-[#4a3f35] transition duration-200 hover:-translate-y-0.5 hover:bg-[#f0ddc1] hover:text-[#5a402a] hover:shadow-[0_8px_18px_rgba(85,58,34,0.18)] after:content-[''] after:absolute after:left-4 after:right-4 after:bottom-1.5 after:h-[2px] after:origin-center after:scale-x-0 after:rounded-full after:bg-[linear-gradient(90deg,#b18053,#d9ab7e)] after:transition-transform after:duration-300 hover:after:scale-x-100"
                @click="mobileOpen = false"
            >樂齡館</a
            >
            <a
                href="#contact"
                class="relative w-full overflow-hidden rounded-md px-4 py-2.5 text-center text-base font-semibold text-[#4a3f35] transition duration-200 hover:-translate-y-0.5 hover:bg-[#f0ddc1] hover:text-[#5a402a] hover:shadow-[0_8px_18px_rgba(85,58,34,0.18)] after:content-[''] after:absolute after:left-4 after:right-4 after:bottom-1.5 after:h-[2px] after:origin-center after:scale-x-0 after:rounded-full after:bg-[linear-gradient(90deg,#b18053,#d9ab7e)] after:transition-transform after:duration-300 hover:after:scale-x-100"
                @click="mobileOpen = false"
            >聯絡我們</a
            >
          </div>
        </nav>
      </div>
    </header>

    <main class="mx-auto w-[min(1200px,calc(100%-2rem))] py-5">
      <section
          id="home"
          class="grid min-h-[620px] content-center justify-items-center gap-5 rounded-[22px] border border-[#d8c2a6]
         bg-cover bg-center px-10 py-16 text-center shadow-[0_24px_44px_rgba(35,23,15,0.18)] max-md:min-h-[440px] max-md:px-5 max-md:py-10"
          :style="heroStyle"
      >
        <p class="text-xs font-bold uppercase tracking-[0.15em] text-[#856246]">Taipei Wanhua Senior Pet Club</p>
        <h1 class="max-w-[960px] text-6xl leading-[1.02] text-[#2d241c] max-md:text-[2.4rem]">
          {{ heroTitle }}
        </h1>
        <p class="max-w-[760px] text-lg leading-relaxed text-[#5b4a3b] max-md:text-base">
          {{ heroDescription }}
        </p>
      </section>

      <section id="about"
               class="mt-5 rounded-2xl border border-[#ccb392] bg-[#fff8eee8] p-8 shadow-[0_20px_36px_rgba(35,23,15,0.14)] backdrop-blur-sm max-md:p-6">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.12em] text-[#8a6a4c]">關於恆寵愛</p>
          <h2 class="mt-1 text-[clamp(2rem,3.2vw,3rem)] text-[#2d241c]">{{ aboutTitle }}</h2>
          <p class="mt-4 whitespace-pre-line text-lg leading-relaxed text-[#5a4c3f] max-md:text-base">
            {{ aboutIntro }}
          </p>
          <p class="mt-4 whitespace-pre-line text-lg text-[#4f4236] max-md:text-base">
            {{ aboutSecondary }}
          </p>
          <div class="mt-4 flex flex-wrap gap-3">
            <button
                v-for="item in aboutModalItems"
                :key="item.id"
                type="button"
                class="inline-flex items-center gap-1 rounded-lg border border-[#d8c2a6] bg-white/75 px-4 py-2 text-base text-[#4e4134] transition duration-200 hover:-translate-y-0.5 hover:bg-[#f6eadb] hover:shadow-[0_10px_18px_rgba(85,58,34,0.12)]"
                @click="activeAboutModal = item.id"
            >
              <component :is="item.icon" :size="16"/>
              <span>{{ item.badge }}</span>
              <span v-if="item.summary">：</span>
              <span
                  v-if="item.summary"
                  class="whitespace-pre-line rounded-md bg-[#f1dfc8] px-2 py-0.5 text-base font-semibold text-[#5b4636]"
              >{{ item.summary }}</span>
            </button>
          </div>
        </div>
      </section>

      <section id="news"
               class="mt-5 rounded-2xl border border-[#ccb392] bg-[#fff8eee8] p-8 shadow-[0_20px_36px_rgba(35,23,15,0.14)] backdrop-blur-sm max-md:p-6">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.12em] text-[#8a6a4c]">最新消息</p>
        </div>

        <p v-if="loadingNews" class="mt-3 text-[#5a4c3f]">載入最新消息中...</p>
        <p v-else-if="newsError" class="mt-3 text-[#8d5d37]">{{ newsError }}</p>

        <div class="mt-5 flex flex-col gap-4">
          <article
              v-for="(item, index) in latestNews"
              :key="item.id"
              class="group grid gap-4 rounded-xl border border-[#d8c2a6] bg-gradient-to-br from-[#fffdf9] to-[#fff4e2] p-5 transition duration-300 hover:-translate-y-1 hover:shadow-[0_16px_28px_rgba(90,63,35,0.16)] md:grid-cols-2 md:items-stretch"
          >
            <img
                v-if="item.imageUrl"
                :src="item.imageUrl"
                :alt="`${item.title} 圖片`"
                class="h-auto max-h-[72vh] w-full rounded-lg bg-[#f6eadc] object-contain p-2 transition duration-300"
                :class="index % 2 === 0 ? 'md:order-1' : 'md:order-2'"
            />
            <div
                class="flex flex-col gap-3"
                :class="[
                item.imageUrl ? (index % 2 === 0 ? 'md:order-2' : 'md:order-1') : 'md:col-span-2',
              ]"
            >
              <div class="inline-flex items-center gap-1.5 text-sm text-[#80664e]">
                <CalendarDays :size="15"/>
                <span>{{ item.publishedAt }}</span>
              </div>
              <h3 class="text-3xl leading-tight text-[#2f271f] max-md:text-2xl">{{ item.title }}</h3>
              <p class="whitespace-pre-line text-lg text-[#5a4c3f] max-md:text-base">{{ item.excerpt }}</p>
              <a
                  v-if="item.link"
                  :href="item.link"
                  class="mt-1 inline-flex items-center gap-1 font-bold text-[#6d4f34]"
                  target="_blank"
                  rel="noreferrer"
              >
                <span>查看詳情</span>
                <ExternalLink :size="14"/>
              </a>
            </div>
          </article>
        </div>
      </section>

      <section
          id="senior"
          class="mt-5 grid items-center gap-5 rounded-2xl border border-[#ccb392] bg-[#fff8eee8] p-8 shadow-[0_20px_36px_rgba(35,23,15,0.14)] backdrop-blur-sm md:grid-cols-[1.25fr_1.05fr] max-md:p-6"
      >
        <div>
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.12em] text-[#8a6a4c]">{{ seniorContent.title }}</p>
            <h2 class="mt-1 whitespace-pre-line text-[clamp(2rem,3.2vw,3rem)] text-[#2d241c]">{{ seniorContent.subtitle }}</h2>
          </div>
          <p v-if="loadingSenior" class="mt-3 text-[#5a4c3f]">載入樂齡館資料中...</p>
          <p v-else-if="seniorError" class="mt-3 text-[#8d5d37]">{{ seniorError }}</p>
          <p class="mt-4 whitespace-pre-line text-lg leading-relaxed text-[#5a4c3f] max-md:text-base">{{ seniorContent.description }}</p>
          <div class="mt-4">
<!--            <p class="text-sm font-bold uppercase tracking-[0.14em] text-[#8a6a4c]">#tags</p>-->
            <div class="mt-2 flex flex-wrap gap-2">
              <span
                  v-for="tag in seniorContent.tags"
                  :key="tag"
                  class="inline-flex rounded-full border border-[#d8c2a6] bg-white/80 px-3 py-1.5 text-sm font-semibold text-[#5a4637]"
              >#{{ tag }}</span>
            </div>
          </div>
        </div>
        <img
            :src="seniorContent.imageUrl"
            :alt="`${seniorContent.title} 圖片`"
            class="h-full min-h-[420px] w-full rounded-xl object-cover contrast-105 saturate-110 transition duration-500 hover:scale-[1.03]"
        />
      </section>

      <section id="articles"
               class="mt-5 rounded-2xl border border-[#ccb392] bg-[#fff8eee8] p-8 shadow-[0_20px_36px_rgba(35,23,15,0.14)] backdrop-blur-sm max-md:p-6">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.12em] text-[#8a6a4c]">{{ articleShareHeading }}</p>
          <p class="mt-3 whitespace-pre-line text-lg leading-relaxed text-[#5a4c3f] max-md:text-base">{{ articleShareIntro }}</p>
        </div>
        <p v-if="articleError" class="mt-3 text-[#8d5d37]">{{ articleError }}</p>
        <div class="mt-5 flex flex-col gap-4">
          <article
              v-for="(item, index) in articleShareItems"
              :key="item.id"
              class="group grid gap-4 rounded-xl border border-[#d8c2a6] bg-gradient-to-br from-[#fffdf9] to-[#fff4e2] p-5 transition duration-300 hover:-translate-y-1 hover:shadow-[0_16px_28px_rgba(90,63,35,0.16)] md:grid-cols-2 md:items-stretch"
          >
            <img
                v-if="item.imageUrl"
                :src="item.imageUrl"
                :alt="`${item.title} 圖片`"
                class="h-auto max-h-[72vh] w-full rounded-lg bg-[#f6eadc] object-contain p-2 transition duration-300"
                :class="index % 2 === 0 ? 'md:order-1' : 'md:order-2'"
            />
            <div
                class="flex flex-col gap-3"
                :class="[
                  item.imageUrl ? (index % 2 === 0 ? 'md:order-2' : 'md:order-1') : 'md:col-span-2',
                ]"
            >
              <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#8a6a4c]">Article Share</p>
              <div v-if="item.publishedAt" class="inline-flex items-center gap-1.5 text-sm text-[#80664e]">
                <CalendarDays :size="15"/>
                <span>{{ item.publishedAt.slice(0, 10) }}</span>
              </div>
              <h3 class="text-3xl leading-tight text-[#2f271f] max-md:text-2xl">{{ item.title }}</h3>
              <p class="whitespace-pre-line text-lg text-[#5a4c3f] max-md:text-base">{{ item.excerpt }}</p>
              <a
                  v-if="item.link"
                  :href="item.link"
                  class="mt-1 inline-flex items-center gap-1 font-bold text-[#6d4f34]"
                  target="_blank"
                  rel="noreferrer"
              >
                <span>查看文章</span>
                <ExternalLink :size="14"/>
              </a>
            </div>
          </article>
        </div>
      </section>
    </main>

    <footer id="contact" class="pb-8 pt-4">
      <div
          class="mx-auto grid w-[min(1200px,calc(100%-2rem))] gap-5 rounded-2xl border border-[#deceb9] bg-gradient-to-br from-[#2f4d42] to-[#335346] p-7 text-[#f8f0e4] md:grid-cols-2"
      >
        <div>
          <h3 class="text-3xl text-[#fff9ef]">聯絡我們</h3>
          <p class="mt-2 inline-flex items-center gap-1.5">
            <MapPin :size="15"/>
            <span class="whitespace-pre-line">{{ storeInfo.address }}</span>
          </p>
          <p class="mt-2 inline-flex items-center gap-1.5">
            <Phone :size="15"/>
            <span>{{ storeInfo.phone }}</span>
          </p>
          <p class="mt-2 inline-flex items-center gap-1.5">
            <Clock3 :size="15"/>
            <span class="whitespace-pre-line">{{ storeInfo.businessHours }}</span>
          </p>
          <h3 class="mt-6 text-3xl text-[#fff9ef]">社群媒體</h3>
          <div class="mt-3 grid gap-2 md:max-w-[280px]">
            <a
                v-for="link in socialLinks"
                :key="link.key"
                :href="link.url"
                class="inline-flex items-center gap-1.5 rounded-xl border border-white/30 px-3 py-2 text-[#fef9f0]"
                target="_blank"
                rel="noopener noreferrer"
            >
              <component :is="link.icon" :size="16"/>
              <span>{{ link.label }}</span>
            </a>
          </div>
        </div>
        <a
            v-if="contactMapUrl"
            :href="contactMapUrl"
            class="overflow-hidden rounded-xl border border-white/20 bg-white/10"
            target="_blank"
            rel="noopener noreferrer"
        >
          <img :src="mapImageUrl" alt="恆寵愛位置地圖"
               class="h-full min-h-[320px] w-full object-cover transition duration-500 hover:scale-[1.03]"/>
        </a>
        <div v-else class="overflow-hidden rounded-xl border border-white/20 bg-white/10">
          <img :src="mapImageUrl" alt="恆寵愛位置地圖"
               class="h-full min-h-[320px] w-full object-cover transition duration-500 hover:scale-[1.03]"/>
        </div>
      </div>
    </footer>

    <Teleport to="body">
      <Transition
          enter-active-class="transition duration-300 ease-out"
          enter-from-class="opacity-0"
          enter-to-class="opacity-100"
          leave-active-class="transition duration-200 ease-in"
          leave-from-class="opacity-100"
          leave-to-class="opacity-0"
      >
        <div
            v-if="activeAboutItem"
            class="fixed inset-0 z-[80] flex items-center justify-center bg-[#2a2119]/55 px-4 py-6"
            @click.self="activeAboutModal = null"
        >
          <Transition
              appear
              enter-active-class="transition duration-300 ease-out"
              enter-from-class="translate-y-4 scale-95 opacity-0"
              enter-to-class="translate-y-0 scale-100 opacity-100"
              leave-active-class="transition duration-200 ease-in"
              leave-from-class="translate-y-0 scale-100 opacity-100"
              leave-to-class="translate-y-3 scale-95 opacity-0"
          >
            <div v-if="activeAboutItem" class="max-h-[calc(100vh-3rem)] w-full max-w-[900px] overflow-y-auto rounded-[28px] border border-[#d9c3a8] bg-[#fff8eee8] p-6 shadow-[0_28px_48px_rgba(26,18,12,0.22)] backdrop-blur-md max-md:p-5">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#8a6a4c]">關於恆寵愛</p>
                  <h3 class="mt-2 text-[2.2rem] leading-tight text-[#2d241c] max-md:text-[1.8rem]">{{ activeAboutItem.title }}</h3>
                </div>
                <button
                    type="button"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#dbc8b0] bg-white/80 text-[#5c4738] transition hover:bg-[#f1dfc8]"
                    @click="activeAboutModal = null"
                    aria-label="關閉彈窗"
                >
                  <X :size="18"/>
                </button>
              </div>
              <img
                  v-if="activeAboutItem.imageUrl"
                  :src="activeAboutItem.imageUrl"
                  :alt="`${activeAboutItem.title} 圖片`"
                  class="mt-5 max-h-[75vh] w-full rounded-[22px] bg-[#f6eadc] object-contain p-2"
              />
              <p class="mt-5 whitespace-pre-line text-lg leading-relaxed text-[#5a4c3f] max-md:text-base">
                {{ activeAboutItem.content }}
              </p>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
