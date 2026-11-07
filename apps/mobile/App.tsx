import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import * as SecureStore from 'expo-secure-store';
import {
  ApiError, HomeAloneClient, mobileLogin, publicConfig,
  type Charge, type Contract, type Conversation, type Identity, type Message, type Ticket,
} from '../../packages/client/src';

const apiUrl = (process.env.EXPO_PUBLIC_API_URL || 'https://demo.rendora.pt').replace(/\/$/, '');
const safeUrl = /^https:\/\/[a-z0-9.-]+(?::[0-9]+)?$/.test(apiUrl) ||
  (__DEV__ && /^http:\/\/(localhost|127\.0\.0\.1|10\.0\.2\.2):[0-9]+$/.test(apiUrl));
const tokenKey = 'rendora.resident.session';
const languageKey = 'rendora.resident.language';
const secureOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };
const demoEmail = 'morador@demo.homealone.pt';
type Language = 'pt' | 'en';
type Translate = (pt: string, en: string) => string;
type Tab = 'home' | 'rent' | 'maintenance' | 'messages';
type Session = { token: string; identity: Identity; demo: boolean };
type PortalData = { contracts: Contract[]; rent: Charge[]; tickets: Ticket[]; conversations: Conversation[] };
const residentClient = (token: string, companyId = '') => new HomeAloneClient(apiUrl, async () => token, companyId);
const todayPeriod = () => {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
};

function Button({ title, onPress, secondary = false, disabled = false, selected }: {
  title: string; onPress: () => void; secondary?: boolean; disabled?: boolean; selected?: boolean;
}) {
  return <Pressable
    accessibilityRole="button"
    accessibilityState={{ disabled, selected }}
    disabled={disabled}
    onPress={onPress}
    style={({ pressed }) => [styles.button, secondary && styles.secondary, selected && styles.selected, (pressed || disabled) && styles.dim]}
  >
    <Text style={[styles.buttonText, secondary && !selected && styles.secondaryText]}>{title}</Text>
  </Pressable>;
}

function Card({ title, detail, badge, children }: { title: string; detail?: string; badge?: string; children?: ReactNode }) {
  return <View style={styles.card}>
    <View style={styles.cardHeader}><Text style={styles.cardTitle}>{title}</Text>{badge && <Text style={styles.badge}>{badge}</Text>}</View>
    {!!detail && <Text style={styles.muted}>{detail}</Text>}
    {children}
  </View>;
}

function localizedStatus(value: string | undefined, t: Translate) {
  const labels: Record<string, [string, string]> = {
    ACTIVE: ['Activo', 'Active'], ENDED: ['Terminado', 'Ended'], CANCELLED: ['Cancelado', 'Cancelled'],
    PENDING: ['Pendente', 'Pending'], PROCESSING: ['Em processamento', 'Processing'],
    PARTIAL: ['Parcial', 'Part paid'], PAID: ['Pago', 'Paid'], FAILED: ['Falhou', 'Failed'],
    REFUNDED: ['Reembolsado', 'Refunded'], DRAFT: ['Por confirmar', 'Awaiting confirmation'],
    NEW: ['Novo', 'New'], TRIAGE: ['Em triagem', 'Under review'], SCHEDULED: ['Agendado', 'Scheduled'],
    IN_PROGRESS: ['Em curso', 'In progress'], WAITING: ['A aguardar', 'Waiting'],
    RESOLVED: ['Resolvido', 'Resolved'], CLOSED: ['Encerrado', 'Closed'],
  };
  return value && labels[value] ? t(...labels[value]) : undefined;
}

export default function App() {
  const [language, setLanguage] = useState<Language>('pt');
  const t: Translate = (pt, en) => language === 'pt' ? pt : en;
  const locale = language === 'pt' ? 'pt-PT' : 'en-GB';
  const [session, setSession] = useState<Session | null>(null);
  const [companyId, setCompanyId] = useState('');
  const [booting, setBooting] = useState(true);
  const [demo, setDemo] = useState<boolean | null>(null);
  const [setupError, setSetupError] = useState(false);
  const [restore, setRestore] = useState(0);
  const [busy, setBusy] = useState(false);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [tab, setTab] = useState<Tab>('home');
  const [portal, setPortal] = useState<PortalData | null>(null);
  const [portalError, setPortalError] = useState(false);
  const [reload, setReload] = useState(0);
  const [conversation, setConversation] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<Message[] | null>(null);
  const [messageError, setMessageError] = useState(false);

  const clearSession = useCallback(async () => {
    setSession(null);
    setCompanyId('');
    setPortal(null);
    setConversation(null);
    setMessages(null);
    setTab('home');
    await SecureStore.deleteItemAsync(tokenKey);
  }, []);

  useEffect(() => {
    let current = true;
    async function initialise() {
      setBooting(true);
      setSetupError(false);
      try {
        const storedLanguage = await SecureStore.getItemAsync(languageKey);
        if (current && (storedLanguage === 'en' || storedLanguage === 'pt')) setLanguage(storedLanguage);
        if (!safeUrl) throw new Error('API origin rejected');
        const config = await publicConfig(apiUrl);
        const sample = config.demo || config.public_demo;
        if (!current) return;
        setDemo(sample);
        const stored = await SecureStore.getItemAsync(tokenKey);
        if (!stored) return;
        let saved: { origin?: string; token?: string } | null;
        try { saved = JSON.parse(stored); } catch { saved = null; }
        if (!saved || saved.origin !== apiUrl || typeof saved.token !== 'string' || !saved.token.startsWith('resident_')) {
          await SecureStore.deleteItemAsync(tokenKey);
          return;
        }
        try {
          const identity = await residentClient(saved.token).identity();
          if (!current) return;
          const companies = identity.companies.filter(company => company.role === 'RESIDENT');
          if (!companies.length) {
            await clearSession();
            return;
          }
          setCompanyId(companies[0].id);
          setSession({ token: saved.token, identity: { ...identity, companies }, demo: sample });
        } catch (failure) {
          if (failure instanceof ApiError && [401, 403].includes(failure.status)) await clearSession();
          else throw failure;
        }
      } catch {
        if (current) setSetupError(true);
      } finally {
        if (current) setBooting(false);
      }
    }
    void initialise();
    return () => { current = false; };
  }, [restore, clearSession]);

  useEffect(() => {
    if (!session || !companyId) return;
    let current = true;
    setPortal(null);
    setPortalError(false);
    const client = residentClient(session.token, companyId);
    Promise.all([client.contracts(), client.charges(todayPeriod()), client.tickets(), client.conversations()])
      .then(([contracts, rent, tickets, conversations]) => {
        if (current) setPortal({ contracts, rent, tickets, conversations });
      })
      .catch((failure: unknown) => {
        if (!current) return;
        if (failure instanceof ApiError && [401, 403].includes(failure.status)) {
          void clearSession().catch(() => {});
        } else setPortalError(true);
      });
    return () => { current = false; };
  }, [session, companyId, reload, clearSession]);

  useEffect(() => {
    if (!session || !companyId || !conversation) return;
    let current = true;
    setMessages(null);
    setMessageError(false);
    residentClient(session.token, companyId).messages(conversation.id)
      .then(rows => { if (current) setMessages(rows); })
      .catch((failure: unknown) => {
        if (!current) return;
        if (failure instanceof ApiError && failure.status === 401) void clearSession().catch(() => {});
        else setMessageError(true);
      });
    return () => { current = false; };
  }, [session, companyId, conversation, reload, clearSession]);

  async function signIn(sample: boolean) {
    setError('');
    setBusy(true);
    let token: string | undefined;
    try {
      const result = await mobileLogin(apiUrl, sample ? demoEmail : email.trim(), sample ? undefined : password);
      if (!result.token?.startsWith('resident_')) {
        setError(t('O servidor ainda não está preparado para esta app.', 'The server is not ready for this app yet.'));
        return;
      }
      token = result.token;
      const identity = await residentClient(token).identity();
      const companies = identity.companies.filter(company => company.role === 'RESIDENT');
      if (!companies.length) {
        await residentClient(token).logout();
        setError(t('A tua conta não tem um portal de inquilino activo.', 'Your account has no active tenant portal.'));
        return;
      }
      await SecureStore.setItemAsync(tokenKey, JSON.stringify({ origin: apiUrl, token }), secureOptions);
      setCompanyId(companies[0].id);
      setSession({ token, identity: { ...identity, companies }, demo: sample });
      setPassword('');
    } catch (failure) {
      if (token) await residentClient(token).logout().catch(() => {});
      setError(failure instanceof ApiError && failure.status === 403
        ? t('A tua conta não tem acesso como inquilino.', 'Your account has no tenant access.')
        : failure instanceof ApiError && failure.status === 401
          ? t('Verifica o email e a palavra-passe.', 'Check your email and password.')
          : t('Não foi possível entrar. Tenta novamente.', 'Sign-in failed. Please try again.'));
    } finally {
      setBusy(false);
    }
  }

  async function signOut() {
    if (busy) return;
    setBusy(true);
    try {
      if (session) await residentClient(session.token).logout();
    } catch {
      // Local sign-out is still available offline; the server session expires independently.
    } finally {
      try { await clearSession(); }
      catch { setError(t('Não foi possível limpar a sessão guardada.', 'The stored session could not be cleared.')); }
      setBusy(false);
    }
  }

  const switchLanguage = (next: Language) => {
    setLanguage(next);
    setError('');
    void SecureStore.setItemAsync(languageKey, next).catch(() => {});
  };
  const money = (cents = 0) => new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' }).format(cents / 100);
  const formatDate = (value: string | null | undefined) => value
    ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T12:00:00Z`))
    : '—';
  const tabs: [Tab, string][] = [
    ['home', t('A minha casa', 'My home')], ['rent', t('Rendas', 'Rent')],
    ['maintenance', t('Manutenção', 'Maintenance')], ['messages', t('Mensagens', 'Messages')],
  ];
  const retry = () => setReload(value => value + 1);
  const activeCompany = session?.identity.companies.find(company => company.id === companyId);

  return <SafeAreaProvider><SafeAreaView style={styles.screen}>
    <StatusBar style="dark"/>
    <View style={styles.header}>
      <View><Text style={styles.brand}>Rendora</Text><Text style={styles.eyebrow}>{t('PORTAL DO INQUILINO', 'TENANT PORTAL')}</Text></View>
      <View style={styles.row}>
        {(['pt', 'en'] as const).map(value => <Button key={value} title={value.toUpperCase()} secondary selected={value === language} onPress={() => switchLanguage(value)}/>)}
      </View>
    </View>
    <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      {booting ? <ActivityIndicator color={colors.teal}/> : setupError ? <Card title={t('Não foi possível ligar ao portal.', 'Could not connect to the portal.')}>
        <Button title={t('Tentar novamente', 'Try again')} onPress={() => setRestore(value => value + 1)}/>
      </Card> : !session ? <Card title={t('A tua casa, num só lugar.', 'Your home, in one place.')}
        detail={t('Consulta o teu contrato, as rendas e as mensagens da equipa que gere o imóvel.', 'See your rental agreement, rent and messages from your property team.')}>
        {demo ? <>
          <Text style={styles.muted}>{t('Demonstração com dados fictícios.', 'Demo with sample data.')}</Text>
          <Button title={t('Entrar como inquilino de demonstração', 'Enter as a demo tenant')} disabled={busy} onPress={() => void signIn(true)}/>
        </> : <>
          <TextInput style={styles.input} accessibilityLabel={t('Email', 'Email')} placeholder={t('Email', 'Email')}
            autoComplete="email" keyboardType="email-address" autoCapitalize="none" value={email} onChangeText={setEmail}/>
          <TextInput style={styles.input} accessibilityLabel={t('Palavra-passe', 'Password')} placeholder={t('Palavra-passe', 'Password')}
            autoComplete="current-password" secureTextEntry value={password} onChangeText={setPassword}/>
          <Button title={t('Entrar', 'Sign in')} disabled={busy || !email.trim() || !password} onPress={() => void signIn(false)}/>
        </>}
        {!!error && <Text accessibilityRole="alert" style={styles.error}>{error}</Text>}
      </Card> : <>
        <View style={styles.sessionHeader}>
          <View style={styles.sessionName}><Text style={styles.heading}>{t('Olá', 'Hello')}, {session.identity.user.name}</Text>
            <Text style={styles.muted}>{activeCompany?.name}{session.demo ? ` · ${t('Demo fictícia', 'Sample demo')}` : ''}</Text></View>
          <Button title={t('Sair', 'Sign out')} secondary disabled={busy} onPress={() => void signOut()}/>
        </View>
        {session.identity.companies.length > 1 && <View style={styles.row}>
          {session.identity.companies.map(company => <Button key={company.id} title={company.name} secondary
            selected={company.id === companyId} onPress={() => { setPortal(null); setConversation(null); setCompanyId(company.id); }}/>)}
        </View>}
        <View style={styles.row}>
          {tabs.map(([id, label]) => <Button key={id} title={label} secondary selected={tab === id}
            onPress={() => { setTab(id); setConversation(null); }}/>)}
        </View>
        {portalError ? <Card title={t('Não foi possível carregar os dados.', 'Could not load your information.')}>
          <Button title={t('Tentar novamente', 'Try again')} onPress={retry}/>
        </Card> : !portal ? <ActivityIndicator color={colors.teal}/> : <>
          {tab === 'home' && (portal.contracts.length ? portal.contracts.map(contract =>
            <Card key={contract.id} title={contract.property_name || t('Contrato', 'Rental agreement')}
              badge={localizedStatus(contract.status, t)} detail={`${formatDate(contract.start_date)} – ${formatDate(contract.end_date)}`}>
              <Text style={styles.emphasis}>{money(contract.rent_cents)} {t('por mês', 'per month')}</Text>
            </Card>) : <Card title={t('Ainda não há contratos.', 'No rental agreements yet.')}/>)}
          {tab === 'rent' && (portal.rent.length ? portal.rent.map(charge =>
            <Card key={charge.id} title={charge.property_name || t('Renda', 'Rent')}
              badge={localizedStatus(charge.status, t)} detail={`${t('Vencimento', 'Due date')}: ${formatDate(charge.due_date)}`}>
              <Text style={styles.emphasis}>{t('Saldo', 'Balance')}: {money(Math.max(0, charge.balance_cents ?? 0))}</Text>
            </Card>) : <Card title={t('Sem rendas neste mês.', 'No rent entries this month.')}/>)}
          {tab === 'maintenance' && (portal.tickets.length ? portal.tickets.map(ticket =>
            <Card key={ticket.id} title={ticket.title || t('Pedido de manutenção', 'Maintenance request')}
              badge={localizedStatus(ticket.status, t)} detail={ticket.property_name}>
              {!!ticket.description && <Text style={styles.muted}>{ticket.description}</Text>}
            </Card>) : <Card title={t('Sem pedidos de manutenção.', 'No maintenance requests.')}/>)}
          {tab === 'messages' && (conversation ? <>
            <Button title={t('Voltar às conversas', 'Back to conversations')} secondary onPress={() => setConversation(null)}/>
            <Text style={styles.heading}>{conversation.subject}</Text>
            {messageError ? <Card title={t('Não foi possível carregar a conversa.', 'Could not load the conversation.')}>
              <Button title={t('Tentar novamente', 'Try again')} onPress={retry}/>
            </Card> : messages ? messages.map(message => <Card key={message.id} title={message.author_name} detail={formatDate(message.created_at)}>
              <Text style={styles.message}>{message.body}</Text>
            </Card>) : <ActivityIndicator color={colors.teal}/>}
          </> : portal.conversations.length ? portal.conversations.map(item =>
            <Card key={item.id} title={item.subject} detail={item.last_message ?? undefined}>
              <Button title={t('Ler conversa', 'Read conversation')} secondary onPress={() => { setMessages(null); setConversation(item); }}/>
            </Card>) : <Card title={t('Ainda não há conversas.', 'No conversations yet.')}/>)}
          {!conversation && <Button title={t('Actualizar', 'Refresh')} secondary onPress={retry}/>}
        </>}
      </>}
    </ScrollView>
  </SafeAreaView></SafeAreaProvider>;
}

const colors = { teal: '#125c59', ink: '#173a38', muted: '#47635f', bg: '#f3f8f6', border: '#bfd4ce' };
const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 18, paddingVertical: 12, gap: 10, borderBottomWidth: 1, borderColor: colors.border, backgroundColor: '#fff' },
  brand: { color: colors.teal, fontSize: 26, fontWeight: '800' },
  eyebrow: { color: colors.muted, fontSize: 11, fontWeight: '700', letterSpacing: 1 },
  content: { padding: 18, gap: 13, width: '100%', maxWidth: 680, alignSelf: 'center' },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 7 },
  heading: { color: colors.ink, fontSize: 23, fontWeight: '700' },
  sessionHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
  sessionName: { flex: 1 },
  muted: { color: colors.muted, fontSize: 15, lineHeight: 22 },
  emphasis: { color: colors.teal, fontWeight: '700', fontSize: 17 },
  message: { color: colors.ink, fontSize: 16, lineHeight: 23 },
  card: { padding: 16, gap: 10, borderWidth: 1, borderColor: colors.border, borderRadius: 14, backgroundColor: '#fff' },
  cardHeader: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, alignItems: 'center' },
  cardTitle: { color: colors.ink, fontWeight: '700', fontSize: 18, flexShrink: 1 },
  badge: { color: colors.teal, backgroundColor: '#e3f1eb', borderRadius: 10, paddingHorizontal: 8, paddingVertical: 4, fontSize: 12, fontWeight: '700' },
  button: { minHeight: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 9, paddingHorizontal: 11, paddingVertical: 8, backgroundColor: colors.teal, borderWidth: 1, borderColor: colors.teal },
  secondary: { backgroundColor: '#fff', borderColor: colors.border },
  selected: { backgroundColor: colors.teal, borderColor: colors.teal },
  buttonText: { fontSize: 14, textAlign: 'center', fontWeight: '700', color: '#fff' },
  secondaryText: { color: colors.teal },
  dim: { opacity: 0.55 },
  input: { color: colors.ink, borderColor: colors.border, borderWidth: 1, borderRadius: 8, padding: 11, minHeight: 44, fontSize: 16 },
  error: { color: '#9b2727', fontSize: 15 },
});
