import styles from './ExchangeBanner.module.css';

type ExchangeBannerProps = {
  exchangeConnected: boolean;
};

export function ExchangeBanner({ exchangeConnected }: ExchangeBannerProps) {
  if (exchangeConnected) {
    return null;
  }
  return (
    <p role="status" className={styles.banner}>
      PBX disconnected
    </p>
  );
}
