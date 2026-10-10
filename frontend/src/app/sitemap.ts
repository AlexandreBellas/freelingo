import type { MetadataRoute } from 'next'
import { INDEXABLE_PATHS, SITE_URL } from '@/lib/seo'

export default function sitemap(): MetadataRoute.Sitemap {
  return INDEXABLE_PATHS.map((path) => ({
    url: new URL(path, SITE_URL).href,
  }))
}
