#!/bin/bash
# LP-NEXT デプロイスクリプト（git pull ベース）
# 使い方: bash /mnt/h/マイドライブ/projects/lp-next/deploy.sh
#
# 前提: GCP の main ブランチに最新コードが push 済みであること
# 開発フロー: GD で編集 → git commit → git push → このスクリプトで GCP に反映

set -e

echo "🚀 LP-NEXT デプロイ開始..."

ssh gcp-lp "cd /home/lp-next/current/lp_reverse_cms && git pull origin main"

echo "✅ デプロイ完了！"
echo "🌐 https://lp-next.jitan.app/current/lp_reverse_cms/"
