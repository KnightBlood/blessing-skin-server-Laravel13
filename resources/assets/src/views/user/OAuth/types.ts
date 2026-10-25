export type App = {
  id: number
  name: string
  /**
   * Passport 13 起客户端密钥以哈希形式存储，列表接口不再返回明文密钥。
   * 该字段仅在创建成功后由前端填入（见 `index.tsx`），刷新页面后为空。
   */
  secret?: string
  /** 仅创建成功时返回一次，供前端展示明文密钥。 */
  plain_secret?: string
  redirect: string
}
